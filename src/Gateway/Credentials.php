<?php

namespace Elcreator\aDataForSeo\Gateway;

use Elcreator\aDataForSeo\Support\Config;

/**
 * An ai.artur.work API key in memory. Like {@see \Elcreator\aDataForSeo\Api\Credentials}
 * it is never serialised, never dumped and never put in a task payload: jobs carry
 * ids and read the key store again when they run.
 */
final class Credentials
{
    public function __construct(
        #[\SensitiveParameter] private readonly string $apiKey,
        public readonly string $source = 'site',
        public readonly ?int $keyId = null,
        public readonly ?string $baseUrl = null
    ) {
    }

    public function apiKey(): string
    {
        return $this->apiKey;
    }

    /** The gateway's site root; the API lives under `/v1`. */
    public function siteUrl(): string
    {
        return rtrim($this->baseUrl ?? Config::gatewayUrl(), '/');
    }

    /** Enough to recognise a key, not enough to use it. */
    public static function mask(string $key): string
    {
        $key = trim($key);

        if ($key === '') {
            return '';
        }

        if (mb_strlen($key) <= 8) {
            return str_repeat('•', mb_strlen($key));
        }

        return mb_substr($key, 0, 4) . str_repeat('•', 8) . mb_substr($key, -4);
    }

    public function masked(): string
    {
        return self::mask($this->apiKey);
    }

    public function __debugInfo(): array
    {
        return ['key' => $this->masked(), 'source' => $this->source];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Credentials are never serialised.');
    }
}
