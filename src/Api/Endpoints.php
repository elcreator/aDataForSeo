<?php

namespace Elcreator\aDataForSeo\Api;

/**
 * What the plugin knows about an endpoint path without asking anybody: free or
 * paid, live or queued, which cache family.
 */
final class Endpoints
{
    /** Paths with these segments are never charged. */
    private const FREE_PATTERNS = [
        '#^appendix/#',
        '#/tasks_ready$#',
        '#/tasks_fixed$#',
        '#/task_get(/|$)#',
        '#/locations(/|$)#',
        '#/languages$#',
        '#/locations_and_languages$#',
        '#/id_list$#',
        '#/categories$#',
        // OnPage results are free once the crawl is paid for.
        '#^on_page/(summary|pages|pages_by_resource|resources|duplicate_tags|duplicate_content|links|redirect_chains|non_indexable|waterfall|keyword_density|microdata|raw_html|content_parsing|errors)(/|$)#',
    ];

    public static function normalise(string $endpoint): string
    {
        return trim(strtolower(str_replace('\\', '/', $endpoint)), '/');
    }

    public static function isFree(string $endpoint): bool
    {
        $endpoint = self::normalise($endpoint);

        foreach (self::FREE_PATTERNS as $pattern) {
            if (preg_match($pattern, $endpoint)) {
                return true;
            }
        }

        return false;
    }

    public static function isTaskGet(string $endpoint): bool
    {
        return (bool) preg_match('#/task_get(/|$)#', self::normalise($endpoint));
    }

    public static function isTaskPost(string $endpoint): bool
    {
        return str_ends_with(self::normalise($endpoint), '/task_post');
    }

    public static function family(string $endpoint): string
    {
        return explode('/', self::normalise($endpoint))[0] ?? '';
    }

    /** Answers worth storing and reusing: never a post (side effect) or a ready list (changes by the minute). */
    public static function cacheable(string $endpoint): bool
    {
        $endpoint = self::normalise($endpoint);

        return !self::isTaskPost($endpoint)
            && !str_ends_with($endpoint, '/tasks_ready')
            && !str_ends_with($endpoint, '/tasks_fixed')
            && $endpoint !== 'appendix/user_data';
    }

    /** `serp/google/organic/task_post` → `serp/google/organic` (where tasks_ready and task_get live). */
    public static function base(string $endpoint): string
    {
        $endpoint = self::normalise($endpoint);

        return (string) preg_replace('#/(task_post|tasks_ready|task_get(/.*)?|live(/.*)?)$#', '', $endpoint);
    }
}
