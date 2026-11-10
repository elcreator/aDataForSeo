<?php

namespace Elcreator\aDataForSeo\Api;

use Elcreator\aDataForSeo\Analysis\Research\Lexicon;
use Elcreator\aDataForSeo\Analysis\Research\Text;
use Elcreator\aDataForSeo\Services\Projects;
use Elcreator\aDataForSeo\Support\Config;
use Elcreator\aDataForSeo\Support\UrlMap;
use Throwable;

/**
 * The offline demo: answers in DataForSEO's real shapes, invented but
 * deterministic, built around the site's own pages. No account, no network,
 * no charge — `cost` fields are what the real call would have cost, so the
 * ledger and the estimates behave as they would.
 *
 * It reproduces the traps the plugin is built for: `google.com/goto`
 * wrappers with the host only in `breadcrumb`, social groups with follower
 * counts, Ads buckets (close variants reporting one volume and one vector),
 * pages missing from `site:`, search pages indexed instead.
 *
 * Posted tasks are remembered in a file so `tasks_ready` and `task_get` work
 * across worker processes.
 */
final class DemoTransport implements Transport
{
    /** A snapshot of `appendix/user_data` prices (docs/DESIGN.md appendix), dollars. */
    public const PRICES = [
        'serp' => ['google' => ['organic' => [
            'task_post' => ['priority_normal' => [['cost_type' => 'per_request', 'cost' => 0.0006]], 'priority_high' => [['cost_type' => 'per_request', 'cost' => 0.0012]]],
            'live' => ['priority_normal' => [['cost_type' => 'per_request', 'cost' => 0.002]]],
        ]]],
        'dataforseo_labs' => ['google' => [
            'keyword_suggestions' => ['live' => ['priority_normal' => [['cost_type' => 'per_request', 'cost' => 0.012], ['cost_type' => 'per_result', 'cost' => 0.00012]]]],
            'ranked_keywords' => ['live' => ['priority_normal' => [['cost_type' => 'per_request', 'cost' => 0.012], ['cost_type' => 'per_result', 'cost' => 0.00012]]]],
            'keyword_overview' => ['live' => ['priority_normal' => [['cost_type' => 'per_request', 'cost' => 0.012], ['cost_type' => 'per_result', 'cost' => 0.00012]]]],
            'bulk_traffic_estimation' => ['live' => ['priority_normal' => [['cost_type' => 'per_request', 'cost' => 0.012], ['cost_type' => 'per_result', 'cost' => 0.00012]]]],
            'serp_competitors' => ['live' => ['priority_normal' => [['cost_type' => 'per_request', 'cost' => 0.012], ['cost_type' => 'per_result', 'cost' => 0.00012]]]],
            'relevant_pages' => ['live' => ['priority_normal' => [['cost_type' => 'per_request', 'cost' => 0.012], ['cost_type' => 'per_result', 'cost' => 0.00012]]]],
        ]],
        'keywords_data' => ['google_ads' => [
            'search_volume' => ['task_post' => ['priority_normal' => [['cost_type' => 'per_request', 'cost' => 0.05]]], 'live' => ['priority_normal' => [['cost_type' => 'per_request', 'cost' => 0.09]]]],
            'keywords_for_keywords' => ['task_post' => ['priority_normal' => [['cost_type' => 'per_request', 'cost' => 0.05]]], 'live' => ['priority_normal' => [['cost_type' => 'per_request', 'cost' => 0.09]]]],
        ]],
        'on_page' => ['task_post' => ['priority_normal' => [['cost_type' => 'per_result', 'cost' => 0.00015]]]],
    ];

    /**
     * Invented portals in the reserved `.example` zone (RFC 2606): no real
     * brand, and no address that could ever resolve.
     */
    public const PORTALS = ['homequest.example', 'nestlist.example', 'roofhunt.example', 'keyspot.example', 'dwellmap.example'];

    /** Imaginary public-domain places used where the demo needs a place of its own. */
    public const PLACES = ['atlantida', 'avalon', 'el-dorado', 'utopie'];

    public function send(string $method, string $url, ?string $body, array $headers, int $timeout, int $connectTimeout): array
    {
        $path = trim((string) preg_replace('#^https?://[^/]+/v3/#', '', $url), '/');
        $tasks = $body === null ? [] : (array) json_decode($body, true);

        try {
            $data = $this->answer($path, $tasks);
        } catch (Throwable $e) {
            $data = ['status_code' => 50000, 'status_message' => 'Demo transport: ' . $e->getMessage(), 'tasks' => []];
        }

        return [200, (string) json_encode($data, JSON_UNESCAPED_UNICODE)];
    }

    private function answer(string $path, array $tasks): array
    {
        if ($path === 'appendix/user_data') {
            return self::envelope([self::task(null, [[
                'login' => 'demo',
                'money' => ['balance' => 50.0, 'total' => 50.0],
                'price' => self::PRICES,
                'backlinks_subscription_expiry_date' => null,
                'llm_mentions_subscription_expiry_date' => null,
            ]], 0)]);
        }

        if (str_ends_with($path, '/task_post')) {
            return $this->post($path, $tasks);
        }

        if (str_ends_with($path, '/tasks_ready')) {
            return $this->ready(Endpoints::base($path));
        }

        if (preg_match('#^(.*)/task_get(?:/(regular|advanced))?/([a-z0-9-]+)$#', $path, $m)) {
            return $this->get($m[1], $m[3], $m[2] ?? 'regular');
        }

        if (preg_match('#^on_page/summary/([a-z0-9-]+)$#', $path, $m)) {
            return self::envelope([self::task(['id' => $m[1]], [$this->onPageSummary($m[1])], 0)]);
        }

        if (str_starts_with($path, 'on_page/')) {
            $task = (array) ($tasks[0] ?? []);

            return self::envelope([self::task($task, [['crawl_progress' => 'finished', 'total_items_count' => 0, 'items' => $this->onPage($path, $task)]], 0)]);
        }

        if ($path === 'dataforseo_labs/locations_and_languages') {
            $locations = [];

            foreach ([[2203, 'cs'], [2616, 'pl'], [2703, 'sk'], [2840, 'en'], [2826, 'en'], [2276, 'de'], [2804, 'uk'], [2804, 'ru']] as [$code, $lang]) {
                $locations[$code]['location_code'] = $code;
                $locations[$code]['available_languages'][] = ['language_code' => $lang, 'keywords' => 1000000, 'serps' => 100000];
            }

            return self::envelope([self::task(null, array_values($locations), 0)]);
        }

        if (str_starts_with($path, 'dataforseo_labs/')) {
            $task = (array) ($tasks[0] ?? []);
            $items = $this->labs($path, $task);

            return self::envelope([self::task($task, [['total_count' => count($items) * 3, 'items_count' => count($items), 'items' => $items]], 0.012 + 0.00012 * count($items))]);
        }

        return ['status_code' => 40400, 'status_message' => 'Not found in the demo.', 'tasks' => []];
    }

    private function post(string $path, array $tasks): array
    {
        $state = self::state();
        $out = [];
        $base = Endpoints::base($path);
        $price = str_starts_with($base, 'on_page') ? 0.00015 * (int) ($tasks[0]['max_crawl_pages'] ?? 100) : (str_starts_with($base, 'keywords_data') ? 0.05 : 0.0006);

        foreach ($tasks as $task) {
            $id = sprintf('%08x-%04x-%04x-0000-%012x', time(), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffffffffffff));
            $state[$id] = ['base' => $base, 'data' => $task, 'tag' => $task['tag'] ?? null];
            $out[] = ['id' => $id, 'status_code' => 20100, 'status_message' => 'Task Created.', 'cost' => $price, 'data' => $task, 'result' => null];
        }

        self::save($state);

        return self::envelope($out, $price * count($out));
    }

    private function ready(string $base): array
    {
        $results = [];

        foreach (self::state() as $id => $task) {
            if ($task['base'] === $base) {
                $results[] = ['id' => $id, 'tag' => $task['tag'], 'endpoint_regular' => '/v3/' . $base . '/task_get/regular/' . $id];
            }
        }

        return self::envelope([self::task(null, $results, 0)]);
    }

    private function get(string $base, string $id, string $variant): array
    {
        $task = self::state()[$id] ?? null;

        if ($task === null) {
            return self::envelope([['id' => $id, 'status_code' => 40401, 'status_message' => 'Task not found.', 'result' => null]]);
        }

        $data = (array) $task['data'];

        if (str_starts_with($base, 'keywords_data')) {
            $result = str_contains($base, 'keywords_for_keywords') ? $this->adsIdeas($data) : $this->adsVolumes($data);

            return self::envelope([self::task($data, $result, 0.05) + ['id' => $id]], 0.05);
        }

        if (str_starts_with($base, 'on_page')) {
            return self::envelope([self::task($data, [['crawl_progress' => 'finished']], 0) + ['id' => $id]]);
        }

        return self::envelope([self::task($data, [$this->serp($data, $variant === 'advanced')], 0.0006) + ['id' => $id]], 0.0006);
    }

    // ------------------------------------------------------------------
    // SERP
    // ------------------------------------------------------------------

    private function serp(array $data, bool $advanced): array
    {
        $keyword = (string) ($data['keyword'] ?? '');
        $depth = (int) ($data['depth'] ?? 10);
        $items = str_starts_with($keyword, 'site:') ? $this->siteResults(substr($keyword, 5)) : $this->organic($keyword, (int) ($data['location_code'] ?? 0), $depth);

        if ($advanced && self::hash($keyword) % 3 === 0) {
            array_unshift($items, ['type' => 'ai_overview', 'rank_group' => 1, 'rank_absolute' => 1]);
        }

        return ['keyword' => $keyword, 'type' => 'organic', 'se_domain' => 'google.com', 'location_code' => $data['location_code'] ?? null,
            'language_code' => $data['language_code'] ?? null, 'items_count' => count($items), 'items' => array_slice($items, 0, max(10, $depth))];
    }

    /** About half of the site's pages are "indexed"; two search pages are indexed instead. */
    private function siteResults(string $host): array
    {
        $urls = [];

        try {
            foreach (UrlMap::for(Projects::current())->urls() as $entry) {
                $urls[] = $entry['url'];
            }
        } catch (Throwable) {
            $urls = [];
        }

        $items = [];
        $position = 1;

        foreach ($urls as $url) {
            if (self::hash($url) % 2 === 0 || $position === 1) {
                $items[] = self::organicItem($position++, $url, 'Page ' . trim((string) parse_url($url, PHP_URL_PATH), '/'), $host);
            }
        }

        foreach (['/search?q=byt', '/search?q=atlantida&page=2'] as $search) {
            $items[] = self::organicItem($position++, 'https://' . $host . $search, 'Search results', $host);
        }

        return $items;
    }

    private function organic(string $keyword, int $location, int $depth): array
    {
        $project = null;

        try {
            $project = Projects::current();
        } catch (Throwable) {
        }

        $hosts = self::PORTALS;
        $own = $project?->host();
        $ownAt = $own !== null && self::hash($keyword . 'own') % 3 === 0 ? 2 + self::hash($keyword) % 15 : null;
        $items = [];

        for ($position = 1; $position <= min($depth, 20); $position++) {
            if ($ownAt === $position && $own !== null) {
                $items[] = self::organicItem($position, 'https://' . $own . '/', ucfirst($keyword) . ' | ' . $own, $own);

                continue;
            }

            $kind = self::hash($keyword . $position) % 10;

            if ($kind === 0) {
                // A social-network group: no URL, only a follower count in the breadcrumb.
                $items[] = ['type' => 'organic', 'rank_group' => $position, 'rank_absolute' => $position, 'url' => null, 'domain' => null,
                    'title' => ucfirst($keyword) . ' — group', 'breadcrumb' => '12K+ followers'];

                continue;
            }

            $host = $hosts[($position + self::hash($keyword)) % count($hosts)];
            $url = 'https://www.' . $host . '/' . Text::fold(str_replace(' ', '-', $keyword));
            $title = $kind === 1 ? 'Apartments and houses — ' . $host : ucfirst($keyword) . ' — ' . $host;
            $item = self::organicItem($position, $url, $title, $host);

            if ($kind === 2 || $kind === 3) {
                // The measured trap: a google.com/goto wrapper, the host only in the breadcrumb.
                $item['url'] = 'https://www.google.com/goto?url=' . rawurlencode($url);
                $item['domain'] = 'www.google.com';
            }

            $items[] = $item;
        }

        return $items;
    }

    private static function organicItem(int $position, string $url, string $title, string $host): array
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        return ['type' => 'organic', 'rank_group' => $position, 'rank_absolute' => $position, 'url' => $url, 'domain' => 'www.' . preg_replace('/^www\./', '', $host),
            'title' => $title, 'breadcrumb' => 'https://www.' . preg_replace('/^www\./', '', $host) . ($path !== '' ? ' › ' . str_replace('/', ' › ', $path) : '')];
    }

    // ------------------------------------------------------------------
    // Keywords
    // ------------------------------------------------------------------

    /** Ads buckets: phrases with the same token bag report one volume and one vector. */
    private function adsVolumes(array $data): array
    {
        $out = [];

        foreach ((array) ($data['keywords'] ?? []) as $keyword) {
            $out[] = self::adsRow((string) $keyword, (string) ($data['language_code'] ?? ''));
        }

        return $out;
    }

    private function adsIdeas(array $data): array
    {
        $out = [];
        $lexicon = new Lexicon();
        $lang = (string) ($data['language_code'] ?? 'en');

        foreach ((array) ($data['keywords'] ?? []) as $seed) {
            foreach ($lexicon->deals() as $deal) {
                $out[] = self::adsRow($lexicon->word($lang, 'deal.' . $deal) . ' ' . $seed, $lang);
            }

            $out[] = self::adsRow($seed, $lang);
        }

        return $out;
    }

    private static function adsRow(string $keyword, string $lang): array
    {
        $bag = Text::bag($keyword);
        $volume = [10, 20, 30, 50, 90, 140, 210, 320, 480, 720, 1300, 2400][self::hash($bag) % 12];

        return [
            'keyword' => mb_strtolower($keyword),
            'location_code' => null,
            'language_code' => $lang,
            'search_volume' => $volume,
            'cpc' => round((self::hash($bag) % 300) / 100, 2),
            'competition' => ['LOW', 'MEDIUM', 'HIGH'][self::hash($bag) % 3],
            'competition_index' => self::hash($bag) % 100,
            'monthly_searches' => self::monthly($volume, $bag),
        ];
    }

    private function labs(string $path, array $task): array
    {
        $lexicon = new Lexicon();
        $lang = (string) ($task['language_code'] ?? 'en');
        $limit = max(1, min(60, (int) ($task['limit'] ?? 20)));

        if (str_contains($path, 'keyword_suggestions')) {
            $seed = (string) ($task['keyword'] ?? '');
            $phrases = [$seed];

            foreach ($lexicon->deals() as $deal) {
                foreach ([$lexicon->word($lang, 'deal.' . $deal) . ' ' . $seed, $seed . ' ' . $lexicon->word($lang, 'deal.' . $deal)] as $phrase) {
                    $phrases[] = $phrase;
                }
            }

            foreach (['2+kk', '3+1', 'centrum', 'levně', 'nové'] as $modifier) {
                $phrases[] = $seed . ' ' . $modifier;
            }

            return array_slice(array_map(static fn ($p) => self::labsItem($p, $lang), array_values(array_unique($phrases))), 0, $limit);
        }

        if (str_contains($path, 'ranked_keywords')) {
            $target = (string) ($task['target'] ?? '');
            $items = [];

            foreach (array_slice($lexicon->types(), 0, 4) as $i => $type) {
                foreach ($lexicon->deals() as $deal) {
                    $items[] = ['keyword_data' => self::labsItem($lexicon->word($lang, 'deal.' . $deal) . ' ' . $lexicon->word($lang, 'type.' . $type) . ' ' . self::PLACES[$i % 2], $lang),
                        'ranked_serp_element' => ['serp_item' => ['rank_group' => 1 + ($i + self::hash($target)) % 10, 'url' => 'https://' . $target . '/']]];
                }
            }

            return array_slice($items, 0, $limit);
        }

        if (str_contains($path, 'keyword_overview')) {
            return array_map(static fn ($k) => self::labsItem((string) $k, $lang), array_slice((array) ($task['keywords'] ?? []), 0, 700));
        }

        if (str_contains($path, 'bulk_traffic_estimation')) {
            return array_map(static fn ($t) => ['target' => $t, 'metrics' => ['organic' => ['etv' => (float) (self::hash((string) $t) % 900000), 'count' => self::hash((string) $t) % 50000]]], (array) ($task['targets'] ?? []));
        }

        if (str_contains($path, 'serp_competitors')) {
            $items = [];

            foreach (self::PORTALS as $i => $domain) {
                $items[] = ['domain' => $domain, 'avg_position' => 2.0 + $i, 'median_position' => 2 + $i, 'rating' => 1000 - $i * 90, 'etv' => 50000.0 / ($i + 1), 'keywords_count' => 120 - $i * 10, 'visibility' => 0.6 / ($i + 1)];
            }

            return $items;
        }

        if (str_contains($path, 'relevant_pages')) {
            $target = (string) ($task['target'] ?? '');
            $out = [];

            foreach (['pronajem-bytu-atlantida-2', 'prodej-domu-avalon', 'pronajem-kancelari-atlantida', 'garaze-atlantida', 'novostavby-el-dorado', 'pozemky-utopie'] as $i => $slug) {
                $out[] = ['page_address' => 'https://www.' . $target . '/' . $slug, 'metrics' => ['organic' => ['etv' => 8000.0 / ($i + 1), 'count' => 300 - $i * 30]]];
            }

            return array_slice($out, 0, $limit);
        }

        return [];
    }

    /** Spelling variants share Labs' `core_keyword` and the Ads bucket. */
    private static function labsItem(string $keyword, string $lang): array
    {
        $keyword = mb_strtolower(trim($keyword));
        $row = self::adsRow($keyword, $lang);

        return [
            'keyword' => $keyword,
            'language_code' => $lang,
            'keyword_info' => ['search_volume' => $row['search_volume'], 'cpc' => $row['cpc'], 'competition' => $row['competition_index'] / 100, 'monthly_searches' => $row['monthly_searches']],
            'keyword_properties' => ['core_keyword' => Text::bag($keyword), 'keyword_difficulty' => $lang === 'ru' || $lang === 'uk' ? null : self::hash($keyword) % 30],
            'search_intent_info' => ['main_intent' => ['commercial', 'transactional', 'informational'][self::hash($keyword) % 3]],
        ];
    }

    // ------------------------------------------------------------------
    // OnPage
    // ------------------------------------------------------------------

    private function onPageSummary(string $id): array
    {
        $pages = count($this->sitePages());

        return ['crawl_progress' => 'finished', 'crawl_status' => ['max_crawl_pages' => 200, 'pages_in_queue' => 0, 'pages_crawled' => $pages],
            'domain_info' => ['name' => self::host(), 'cms' => 'Evolution CMS', 'ip' => '127.0.0.1'], 'page_metrics' => ['onpage_score' => 82.5]];
    }

    private function onPage(string $path, array $task): array
    {
        $pages = $this->sitePages();

        if ($path === 'on_page/pages') {
            $items = [];

            foreach ($pages as $i => $url) {
                $h = self::hash($url);
                $title = $h % 5 === 0 ? '' : ($h % 5 === 1 ? 'Welcome' : 'Page ' . ($i + 1) . ' — ' . self::host());
                $items[] = [
                    'url' => $url, 'status_code' => 200, 'resource_type' => 'html',
                    'meta' => ['title' => $title, 'description' => $h % 3 === 0 ? '' : 'Description of page ' . ($i + 1), 'canonical' => $url, 'htags' => ['h1' => $h % 4 === 0 ? [] : ['Heading']]],
                    'checks' => ['no_title' => $title === '', 'no_description' => $h % 3 === 0, 'title_too_long' => false, 'no_h1_tag' => $h % 4 === 0,
                        'is_4xx_code' => false, 'is_orphan_page' => $h % 7 === 0, 'canonical_to_redirect' => false],
                ];
            }

            $items[] = ['url' => 'https://' . self::host() . '/old-offers/flat-123', 'status_code' => 404, 'meta' => [], 'checks' => ['is_4xx_code' => true]];

            return $items;
        }

        if ($path === 'on_page/duplicate_tags') {
            if (($task['type'] ?? '') !== 'duplicate_title') {
                return [];
            }

            $dupes = array_values(array_filter($pages, static fn ($u) => self::hash($u) % 5 === 1));

            return count($dupes) > 1 ? [['accumulator' => 'Welcome', 'total_count' => count($dupes), 'pages' => array_map(static fn ($u) => ['url' => $u], $dupes)]] : [];
        }

        if ($path === 'on_page/redirect_chains') {
            return [['from_url' => 'http://' . self::host() . '/', 'to_url' => 'https://' . self::host() . '/', 'is_redirect_loop' => false, 'chain' => [['from' => 'http://' . self::host() . '/', 'to' => 'https://www.' . self::host() . '/'], ['from' => 'https://www.' . self::host() . '/', 'to' => 'https://' . self::host() . '/']]]];
        }

        if ($path === 'on_page/links') {
            return isset($pages[0]) ? [['link_from' => $pages[0], 'link_to' => 'https://' . self::host() . '/old-offers/flat-123', 'type' => 'anchor', 'is_broken' => true, 'page_to_status_code' => 404]] : [];
        }

        return [];
    }

    /** @return string[] */
    private function sitePages(): array
    {
        try {
            return array_values(array_unique(array_map(static fn ($e) => $e['url'], UrlMap::for(Projects::current())->urls())));
        } catch (Throwable) {
            return ['https://' . self::host() . '/'];
        }
    }

    private static function host(): string
    {
        try {
            return Projects::current()->host();
        } catch (Throwable) {
            return 'example.com';
        }
    }

    // ------------------------------------------------------------------

    private static function monthly(int $volume, string $seed): array
    {
        $out = [];
        $year = (int) date('Y') - 1;
        $month = (int) date('n');

        for ($i = 0; $i < 12; $i++) {
            $m = ($month + $i - 1) % 12 + 1;
            $y = $year + intdiv($month + $i - 1, 12);
            $out[] = ['year' => $y, 'month' => $m, 'search_volume' => (int) round($volume * (0.8 + 0.4 * ((self::hash($seed) + $i * 7) % 10) / 10))];
        }

        return $out;
    }

    private static function hash(string $value): int
    {
        return (int) sprintf('%u', crc32($value));
    }

    private static function task(?array $data, array $result, float $cost): array
    {
        return ['id' => sprintf('%08x-0000-0000-0000-%012x', time(), mt_rand(0, 0xffffffffffff)), 'status_code' => 20000, 'status_message' => 'Ok.',
            'cost' => round($cost, 6), 'result_count' => count($result), 'data' => $data ?? [], 'result' => $result];
    }

    private static function envelope(array $tasks, ?float $cost = null): array
    {
        $cost ??= array_sum(array_map(static fn ($t) => (float) ($t['cost'] ?? 0), $tasks));

        return ['version' => '0.1.demo', 'status_code' => 20000, 'status_message' => 'Ok.', 'time' => '0.01 sec.', 'cost' => round($cost, 6),
            'tasks_count' => count($tasks), 'tasks_error' => 0, 'tasks' => $tasks];
    }

    private static function state(): array
    {
        $file = Config::storagePath('demo/tasks.json');

        return is_file($file) ? (array) json_decode((string) file_get_contents($file), true) : [];
    }

    private static function save(array $state): void
    {
        $file = Config::storagePath('demo/tasks.json');

        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0775, true);
        }

        // The last 2000 tasks are plenty for a demo.
        file_put_contents($file, json_encode(array_slice($state, -2000, null, true)), LOCK_EX);
    }
}
