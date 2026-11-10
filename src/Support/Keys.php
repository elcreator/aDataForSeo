<?php

namespace Elcreator\aDataForSeo\Support;

use Carbon\Carbon;
use Elcreator\aDataForSeo\Api\Client;
use Elcreator\aDataForSeo\Api\Credentials;
use Elcreator\aDataForSeo\Api\Pricing;
use Elcreator\aDataForSeo\Models\Key;

/**
 * Which DataForSEO account a manager's work is billed to.
 *
 * The manager's own key, else the site key, else DATAFORSEO_LOGIN /
 * DATAFORSEO_PASSWORD from the environment, else none. The password is
 * decrypted only here and only into a {@see Credentials}, which refuses to be
 * serialised.
 */
final class Keys
{
    public const ENVS = ['prod', 'sandbox', 'demo'];

    public static function for(Principal $principal): ?Credentials
    {
        $own = self::userKey($principal->id);

        if ($own !== null && ($credentials = self::credentialsOf($own)) !== null) {
            return $credentials;
        }

        $site = self::siteKey();

        if ($site !== null && ($credentials = self::credentialsOf($site)) !== null) {
            return $credentials;
        }

        $login = trim((string) getenv('DATAFORSEO_LOGIN'));
        $password = (string) getenv('DATAFORSEO_PASSWORD');

        if ($login !== '' && $password !== '') {
            $env = Config::env() === 'demo' ? 'prod' : Config::env();

            return new Credentials($login, $password, $env, 'environment');
        }

        return Config::env() === 'demo' ? Credentials::demo() : null;
    }

    public static function siteKey(): ?Key
    {
        return Key::query()->where('scope', 'site')->orderByDesc('id')->first();
    }

    public static function userKey(int $userId): ?Key
    {
        return $userId > 0 ? Key::query()->where('scope', 'user')->where('user_id', $userId)->orderByDesc('id')->first() : null;
    }

    public static function credentialsOf(Key $key): ?Credentials
    {
        if ($key->env === 'demo') {
            return new Credentials('demo', 'demo', 'demo', 'demo', (int) $key->getKey());
        }

        $password = Crypt::decrypt($key->password_enc);

        if ($password === null || trim((string) $key->login) === '') {
            return null;
        }

        return new Credentials((string) $key->login, $password, (string) $key->env, (string) $key->scope, (int) $key->getKey());
    }

    /**
     * Store a key. A blank password keeps the stored one (the form never
     * receives it back); a login change without a password is refused by the caller.
     */
    public static function save(string $scope, ?int $userId, string $login, #[\SensitiveParameter] string $password, string $env): Key
    {
        $scope = $scope === 'user' ? 'user' : 'site';
        $env = in_array($env, self::ENVS, true) ? $env : 'prod';
        $key = $scope === 'user' ? self::userKey((int) $userId) : self::siteKey();
        $key ??= new Key(['scope' => $scope, 'user_id' => $scope === 'user' ? $userId : null]);

        $changed = $key->login !== $login || $key->env !== $env || $password !== '';
        $key->login = $env === 'demo' ? 'demo' : trim($login);
        $key->env = $env;

        if ($password !== '') {
            $key->password_enc = Crypt::encrypt($password);
        }

        if ($changed) {
            $key->verified_at = null;
        }

        $key->save();

        return $key;
    }

    /**
     * Ask DataForSEO (free) who this is: balance, prices, subscriptions. The
     * price table is kept for the estimator as a side effect.
     *
     * @return array{balance: ?float, subscriptions: array<string, ?string>}
     */
    public static function verify(Credentials $credentials, ?Key $key = null): array
    {
        $data = (new Client($credentials))->userData();

        if (is_array($data['price'] ?? null)) {
            Pricing::store($data['price']);
        }

        $summary = [
            'balance' => isset($data['money']['balance']) ? (float) $data['money']['balance'] : null,
            'subscriptions' => [
                'backlinks' => $data['backlinks_subscription_expiry_date'] ?? null,
                'llm_mentions' => $data['llm_mentions_subscription_expiry_date'] ?? null,
            ],
        ];

        if ($key !== null) {
            $key->forceFill([
                'balance' => $summary['balance'],
                'subscriptions' => $summary['subscriptions'],
                'verified_at' => Carbon::now(),
            ])->save();
        }

        return $summary;
    }

    /** Backlinks and LLM Mentions are shown only while their subscription date is in the future. */
    public static function subscriptionActive(?array $subscriptions, string $name): bool
    {
        $date = $subscriptions[$name] ?? null;

        if (!is_string($date) || trim($date) === '') {
            return false;
        }

        try {
            return Carbon::parse($date)->isFuture();
        } catch (\Throwable) {
            return false;
        }
    }

    /** What the page may know about the key in use: never the password, the login masked. */
    public static function describe(Principal $principal): array
    {
        $key = self::userKey($principal->id) ?? self::siteKey();
        $credentials = self::for($principal);

        return [
            'present' => $credentials !== null,
            'source' => $credentials?->source,
            'env' => $credentials?->env,
            'login' => $credentials === null || $credentials->env === 'demo' ? null : $credentials->maskedLogin(),
            'scope' => $key?->scope,
            'verified_at' => $key?->verified_at?->toIso8601String(),
            'balance' => $key?->balance,
            'subscriptions' => [
                'backlinks' => self::subscriptionActive($key?->subscriptions, 'backlinks'),
                'llm_mentions' => self::subscriptionActive($key?->subscriptions, 'llm_mentions'),
            ],
        ];
    }
}
