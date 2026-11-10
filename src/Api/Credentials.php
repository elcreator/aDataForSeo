<?php

namespace Elcreator\aDataForSeo\Api;

/**
 * A DataForSEO login in memory. Never serialised, never dumped, never put in
 * a task payload: jobs carry ids and re-read the key store when they run.
 */
final class Credentials
{
    public function __construct(
        public readonly string $login,
        #[\SensitiveParameter] private readonly string $password,
        public readonly string $env = 'prod',
        public readonly string $source = 'site',
        public readonly ?int $keyId = null
    ) {
    }

    /** The offline demo needs no account. */
    public static function demo(): self
    {
        return new self('demo', 'demo', 'demo', 'demo');
    }

    public function password(): string
    {
        return $this->password;
    }

    public function authorization(): string
    {
        return 'Basic ' . base64_encode($this->login . ':' . $this->password);
    }

    public function maskedLogin(): string
    {
        return self::mask($this->login);
    }

    public static function mask(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $at = strpos($value, '@');

        if ($at !== false && $at > 0) {
            return mb_substr($value, 0, 1) . str_repeat('•', max(1, $at - 1)) . substr($value, $at);
        }

        return mb_substr($value, 0, 2) . str_repeat('•', max(3, mb_strlen($value) - 2));
    }

    public function __debugInfo(): array
    {
        return ['login' => $this->maskedLogin(), 'env' => $this->env, 'source' => $this->source];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Credentials are never serialised.');
    }
}
