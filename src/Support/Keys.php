<?php

namespace Elcreator\aDataForSeo\Support;

use Carbon\Carbon;
use Elcreator\aDataForSeo\Api\Client;
use Elcreator\aDataForSeo\Api\Credentials;
use Elcreator\aDataForSeo\Api\Pricing;
use Elcreator\aDataForSeo\Gateway;
use Elcreator\aDataForSeo\Models\Key;
use Elcreator\aDataForSeo\Models\Project;

/**
 * Which DataForSEO account a manager's work is billed to.
 *
 * The manager's own key, else the site key, else DATAFORSEO_LOGIN /
 * DATAFORSEO_PASSWORD from the environment, else none. The password is
 * decrypted only here and only into a {@see Credentials}, which refuses to be
 * serialised.
 *
 * A second kind of secret lives in the same table (`provider` = `gateway`): an
 * ai.artur.work API key, encrypted the same way, for projects that pay from that
 * balance instead. The two kinds never answer each other's lookups.
 */
final class Keys
{
    public const ENVS = ['prod', 'sandbox', 'demo'];

    public const PROVIDER_DATAFORSEO = 'dataforseo';
    public const PROVIDER_GATEWAY = 'gateway';

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

    public static function siteKey(string $provider = self::PROVIDER_DATAFORSEO): ?Key
    {
        return Key::query()->where('provider', $provider)->where('scope', 'site')->orderByDesc('id')->first();
    }

    public static function userKey(int $userId, string $provider = self::PROVIDER_DATAFORSEO): ?Key
    {
        return $userId > 0 ? Key::query()->where('provider', $provider)->where('scope', 'user')->where('user_id', $userId)->orderByDesc('id')->first() : null;
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

    /**
     * What the page may know about the key in use: never the password, the login masked.
     * For a project that pays from the gateway it is the gateway key that is described.
     */
    public static function describe(Principal $principal, ?Project $project = null): array
    {
        if ($project !== null && Billing::isGateway($project)) {
            return self::describeGateway($principal);
        }

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
    // ------------------------------------------------------------------
    // The ai.artur.work key
    // ------------------------------------------------------------------

    /** The manager's own gateway key, else the site's, else none. */
    public static function gatewayFor(Principal $principal): ?Gateway\Credentials
    {
        foreach ([self::userKey($principal->id, self::PROVIDER_GATEWAY), self::siteKey(self::PROVIDER_GATEWAY)] as $row) {
            if ($row !== null && ($credentials = self::gatewayCredentialsOf($row)) !== null) {
                return $credentials;
            }
        }

        return null;
    }

    public static function gatewayCredentialsOf(Key $key): ?Gateway\Credentials
    {
        $apiKey = Crypt::decrypt($key->password_enc);

        return $apiKey === null || trim($apiKey) === '' ? null : new Gateway\Credentials($apiKey, (string) $key->scope, (int) $key->getKey());
    }

    /** Store (or replace) a gateway key. The row shows only its mask in `login`; the key itself is encrypted. */
    public static function saveGateway(string $scope, ?int $userId, #[\SensitiveParameter] string $apiKey): Key
    {
        $scope = $scope === 'user' ? 'user' : 'site';
        $apiKey = trim($apiKey);
        $key = $scope === 'user' ? self::userKey((int) $userId, self::PROVIDER_GATEWAY) : self::siteKey(self::PROVIDER_GATEWAY);
        $key ??= new Key(['scope' => $scope, 'user_id' => $scope === 'user' ? $userId : null, 'provider' => self::PROVIDER_GATEWAY]);

        $key->forceFill([
            'login' => Gateway\Credentials::mask($apiKey),
            'env' => 'gateway',
            'password_enc' => Crypt::encrypt($apiKey),
            'verified_at' => null,
            'balance' => null,
            'subscriptions' => null,
        ])->save();

        return $key;
    }

    /**
     * Ask the gateway (free) whose key this is and what it holds: the balance in euros, the
     * key's cap and where to top up, and the price list (proof that the analytics are on).
     *
     * @return array{balance: ?float, top_up_url: ?string, cap_eur: ?float}
     * @throws \Elcreator\aDataForSeo\Api\ApiException
     */
    public static function verifyGateway(Gateway\Credentials $credentials, ?Key $key = null): array
    {
        $client = new Gateway\Client($credentials);
        $account = $client->account();
        $client->prices();

        $summary = [
            'balance' => is_numeric($account['balanceEur'] ?? null) ? (float) $account['balanceEur'] : null,
            'top_up_url' => is_string($account['topUpUrl'] ?? null) ? $account['topUpUrl'] : null,
            'cap_eur' => is_numeric($account['key']['capEur'] ?? null) ? (float) $account['key']['capEur'] : null,
        ];

        if ($key !== null) {
            $key->forceFill(['balance' => $summary['balance'], 'subscriptions' => ['top_up_url' => $summary['top_up_url'], 'cap_eur' => $summary['cap_eur']], 'verified_at' => Carbon::now()])->save();
        }

        return $summary;
    }

    /** The gateway key as the page sees it: shaped like {@see self::describe()} so one header serves both. */
    public static function describeGateway(Principal $principal): array
    {
        $key = self::userKey($principal->id, self::PROVIDER_GATEWAY) ?? self::siteKey(self::PROVIDER_GATEWAY);
        $credentials = self::gatewayFor($principal);
        $meta = (array) ($key?->subscriptions ?? []);

        return [
            'present' => $credentials !== null,
            'source' => $credentials?->source,
            'env' => 'gateway',
            'login' => $credentials?->masked(),
            'scope' => $key?->scope,
            'verified_at' => $key?->verified_at?->toIso8601String(),
            'balance' => $key?->balance,
            'subscriptions' => ['backlinks' => false, 'llm_mentions' => false],
            'billing' => Billing::GATEWAY,
            'currency' => Billing::CURRENCY_GATEWAY,
            'top_up_url' => $meta['top_up_url'] ?? null,
            'cap_eur' => $meta['cap_eur'] ?? null,
            'gateway_url' => Config::gatewayUrl(),
        ];
    }
}
