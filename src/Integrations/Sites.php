<?php

namespace Elcreator\aDataForSeo\Integrations;

use Throwable;

/**
 * sLang and sMultisite, asked only when they are installed and switched on.
 */
final class Sites
{
    private const SLANG = 'Seiger\sLang\Facades\sLang';
    private const MULTISITE = 'Seiger\sMultisite\Models\sMultisite';

    public static function hasSLang(): bool
    {
        try {
            return class_exists(self::SLANG) && (bool) evo()->getConfig('check_sLang', false);
        } catch (Throwable) {
            return false;
        }
    }

    public static function hasMultisite(): bool
    {
        try {
            return class_exists(self::MULTISITE) && (bool) evo()->getConfig('check_sMultisite', false);
        } catch (Throwable) {
            return false;
        }
    }

    /** @return string[] sLang languages, or none */
    public static function languages(): array
    {
        if (!self::hasSLang()) {
            return [];
        }

        try {
            return array_values(array_map('strval', (array) call_user_func([self::SLANG, 'langConfig'])));
        } catch (Throwable) {
            return [];
        }
    }

    /** The language key sSeo stores fields under: the sLang default, or `base`. */
    public static function defaultLanguage(): string
    {
        if (!self::hasSLang()) {
            return 'base';
        }

        try {
            return (string) call_user_func([self::SLANG, 'langDefault']) ?: 'base';
        } catch (Throwable) {
            return 'base';
        }
    }

    public static function domainKey(int $documentId): string
    {
        if (!self::hasMultisite() || !SSeo::available()) {
            return 'default';
        }

        try {
            return (string) call_user_func(['Seiger\sSeo\Facades\sSeo', 'resolveDocumentDomainKey'], $documentId, 'default');
        } catch (Throwable) {
            return 'default';
        }
    }

    /** @return array<int, array{key: string, domain: string}> */
    public static function domains(): array
    {
        if (!self::hasMultisite()) {
            return [];
        }

        try {
            return call_user_func([self::MULTISITE, 'query'])->where('active', 1)->get()
                ->map(static fn ($d) => ['key' => (string) $d->key, 'domain' => (string) $d->domain])->all();
        } catch (Throwable) {
            return [];
        }
    }
}
