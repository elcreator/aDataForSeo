<?php

namespace Elcreator\aDataForSeo\Support;

use Elcreator\aDataForSeo\Integrations\Sites;
use Elcreator\aDataForSeo\Models\Project;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * URL ⇄ resource for one project: which document a URL from a SERP or a crawl
 * is, and the URL of a document.
 *
 * Built from published, undeleted documents through `evo()->makeUrl()`, with
 * sLang prefixes and sMultisite domain keys when those are installed. Cached in
 * a file; the document listeners call {@see invalidate()} on save and
 * (un)publish.
 */
final class UrlMap
{
    /** @var array<int, self> */
    private static array $instances = [];

    private static ?\Closure $provider = null;

    /** @var array<string, array{type: string, id: int, lang: string, domain_key: string, url: string}> */
    private array $byPath = [];

    /** @var array<int, array{type: string, id: int, lang: string, domain_key: string, url: string}> */
    private array $entries = [];

    /** @param array<int, array{type?: string, id: int, lang?: string, domain_key?: string, url: string}> $entries */
    private function __construct(array $entries)
    {
        foreach ($entries as $entry) {
            $entry = [
                'type' => (string) ($entry['type'] ?? 'document'),
                'id' => (int) $entry['id'],
                'lang' => (string) ($entry['lang'] ?? 'base'),
                'domain_key' => (string) ($entry['domain_key'] ?? 'default'),
                'url' => (string) $entry['url'],
            ];
            $this->entries[] = $entry;
            $this->byPath[self::normalise($entry['url'])] ??= $entry;
        }
    }

    /** Test seam: a closure returning the entries instead of the CMS. */
    public static function useProvider(?\Closure $provider): void
    {
        self::$provider = $provider;
        self::$instances = [];
    }

    public static function for(Project $project): self
    {
        $id = (int) $project->getKey();

        if (isset(self::$instances[$id])) {
            return self::$instances[$id];
        }

        if (self::$provider !== null) {
            return self::$instances[$id] = new self((self::$provider)($project));
        }

        $file = Config::storagePath('urlmap-' . $id . '.json');
        $cached = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;

        if (is_array($cached) && ($cached['version'] ?? null) === self::version()) {
            return self::$instances[$id] = new self((array) $cached['entries']);
        }

        $entries = self::build($project);

        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0775, true);
        }

        @file_put_contents($file, json_encode(['version' => self::version(), 'entries' => $entries]), LOCK_EX);

        return self::$instances[$id] = new self($entries);
    }

    public static function invalidate(): void
    {
        self::$instances = [];
        $file = Config::storagePath('urlmap.version');

        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0775, true);
        }

        @file_put_contents($file, (string) microtime(true));
    }

    private static function version(): string
    {
        $file = Config::storagePath('urlmap.version');

        return is_file($file) ? trim((string) @file_get_contents($file)) : '0';
    }

    /**
     * The comparable form of a URL: lower-case path, no host, no fragment, no
     * trailing slash, query kept (a `?page=2` is a different page to Google).
     */
    public static function normalise(string $url): string
    {
        $url = trim($url);
        $parts = parse_url(preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) ? $url : 'http://x/' . ltrim($url, '/'));
        $path = '/' . trim(rawurldecode((string) ($parts['path'] ?? '/')), '/');
        $path = mb_strtolower($path);

        if (str_ends_with($path, '/index.html') || str_ends_with($path, '/index.php')) {
            $path = (string) preg_replace('#/index\.(html|php)$#', '', $path) ?: '/';
        }

        $query = (string) ($parts['query'] ?? '');

        return ($path === '' ? '/' : $path) . ($query !== '' ? '?' . $query : '');
    }

    /** Same site? Ignores scheme and a leading www. */
    public static function sameHost(string $url, string $host): bool
    {
        $urlHost = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $urlHost !== '' && preg_replace('/^www\./', '', $urlHost) === preg_replace('/^www\./', '', strtolower($host));
    }

    /** @return array{type: string, id: int, lang: string, domain_key: string, url: string}|null */
    public function resolve(string $url): ?array
    {
        return $this->byPath[self::normalise($url)] ?? null;
    }

    /** @return array<int, array{type: string, id: int, lang: string, domain_key: string, url: string}> */
    public function urls(): array
    {
        return $this->entries;
    }

    public function urlOf(int $id, string $lang = 'base'): ?string
    {
        foreach ($this->entries as $entry) {
            if ($entry['id'] === $id && ($entry['lang'] === $lang || $lang === 'base')) {
                return $entry['url'];
            }
        }

        return null;
    }

    /**
     * Under artisan the CMS derives its base URL from the script's folder, so
     * every address it builds starts with `/core/`; a web request never does.
     */
    public static function withoutCliBase(string $path, ?string $baseUrl = null, ?bool $cli = null): string
    {
        $baseUrl ??= defined('EVO_BASE_URL') ? (string) EVO_BASE_URL : '/';
        $cli ??= PHP_SAPI === 'cli';
        // `php core/artisan` from the site root gives `core/`, from elsewhere `/path/core/`.
        $baseUrl = '/' . ltrim($baseUrl, '/');

        if ($cli && str_ends_with($baseUrl, '/core/') && str_starts_with($path, $baseUrl)) {
            return '/' . substr($path, strlen($baseUrl));
        }

        return $path;
    }

    /**
     * The friendly path the CMS would build from the aliases: prefix, the
     * parents' aliases with `use_alias_path`, suffix; the start page is `/`.
     * Null when friendly URLs are off.
     *
     * @param array<int, array{alias: string, parent: int}> $tree
     */
    public static function aliasPath(int $id, array $tree): ?string
    {
        $config = static fn (string $key, $default = null) => evo()->getConfig($key, $default);

        if ((int) $config('site_start', 1) === $id) {
            return '/';
        }

        if (!(bool) $config('friendly_urls', false) || !isset($tree[$id]) || $tree[$id]['alias'] === '') {
            return null;
        }

        $segments = [$tree[$id]['alias']];

        if ((bool) $config('use_alias_path', false)) {
            $parent = $tree[$id]['parent'];
            $guard = 0;

            while ($parent > 0 && isset($tree[$parent]) && $guard++ < 50) {
                if ($tree[$parent]['alias'] !== '') {
                    array_unshift($segments, $tree[$parent]['alias']);
                }

                $parent = $tree[$parent]['parent'];
            }
        }

        return '/' . (string) $config('friendly_url_prefix', '') . implode('/', array_map('rawurlencode', $segments)) . (string) $config('friendly_url_suffix', '');
    }

    /** @return array<int, array<string, mixed>> */
    private static function build(Project $project): array
    {
        try {
            $rows = DB::table('site_content')
                ->where('published', 1)->where('deleted', 0)->where('type', 'document')
                ->orderBy('id')->limit(20000)->get(['id', 'alias', 'parent']);
            $tree = DB::table('site_content')->where('deleted', 0)->get(['id', 'alias', 'parent'])
                ->keyBy('id')->map(static fn ($r) => ['alias' => (string) $r->alias, 'parent' => (int) $r->parent])->all();
        } catch (Throwable) {
            return [];
        }

        $languages = Sites::languages();
        $default = Sites::defaultLanguage();
        $entries = [];
        // The CMS's own site_url is unreliable outside a web request (a worker, a command): only the path is taken from it.
        $domain = strtolower(trim((string) $project->domain));
        $scheme = preg_match('/:\d+$|^localhost/', $domain) ? 'http' : 'https';
        $base = $scheme . '://' . preg_replace('#^[a-z]+://#', '', rtrim($domain, '/'));

        foreach ($rows as $row) {
            try {
                $made = (string) evo()->makeUrl((int) $row->id);
            } catch (Throwable) {
                continue;
            }

            $path = '/' . ltrim((string) parse_url($made, PHP_URL_PATH), '/');
            $path = self::withoutCliBase($path);
            $alias = (string) $row->alias;

            // A process that started before the document existed (a worker, this command) has a stale
            // alias cache in the CMS and gets no friendly URL: build it from the aliases instead.
            if ($alias !== '' && !str_contains(mb_strtolower(rawurldecode($path)), mb_strtolower($alias))) {
                $path = self::aliasPath((int) $row->id, $tree) ?? $path;
            }

            $url = $base . ($path === '/' ? '/' : $path);

            $domainKey = Sites::domainKey((int) $row->id);

            if ($project->domain_key !== 'default' && $domainKey !== $project->domain_key) {
                continue;
            }

            $entries[] = ['type' => 'document', 'id' => (int) $row->id, 'lang' => $default, 'domain_key' => $domainKey, 'url' => $url];

            foreach ($languages as $lang) {
                if ($lang === $default) {
                    continue;
                }

                $parts = parse_url($url);
                $prefixed = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . '/' . $lang . '/' . ltrim((string) ($parts['path'] ?? ''), '/');
                $entries[] = ['type' => 'document', 'id' => (int) $row->id, 'lang' => $lang, 'domain_key' => $domainKey, 'url' => $prefixed];
            }
        }

        return $entries;
    }
}
