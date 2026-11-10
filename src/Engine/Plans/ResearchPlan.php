<?php

namespace Elcreator\aDataForSeo\Engine\Plans;

use Elcreator\aDataForSeo\Analysis\Research\Lexicon;
use Elcreator\aDataForSeo\Analysis\Research\Text;
use Elcreator\aDataForSeo\Api\Client;
use Elcreator\aDataForSeo\Api\Context;
use Elcreator\aDataForSeo\Models\Job;
use Elcreator\aDataForSeo\Models\Project;
use Elcreator\aDataForSeo\Support\Config;
use Throwable;

/**
 * Demand: what people search for in this site's markets and languages.
 *
 * Labs covers about 90 countries and only their local language, so the
 * plan asks `dataforseo_labs/locations_and_languages` (free) and never
 * assumes: a covered language gets Labs suggestions and competitors' ranked
 * keywords; any other gets Google Ads volumes for generated candidates plus
 * Ads ideas from the seeds. Origin demand (people abroad searching for this
 * market) is measured separately and never added to real demand.
 */
final class ResearchPlan extends Plan
{
    public const LABS_LOCATIONS = 'dataforseo_labs/locations_and_languages';
    public const SUGGESTIONS = 'dataforseo_labs/google/keyword_suggestions/live';
    public const RANKED = 'dataforseo_labs/google/ranked_keywords/live';
    public const OVERVIEW = 'dataforseo_labs/google/keyword_overview/live';
    public const ADS_VOLUME = 'keywords_data/google_ads/search_volume';
    public const ADS_IDEAS = 'keywords_data/google_ads/keywords_for_keywords';

    private const MAX_SEEDS = 8;

    public function build(Job $job, Project $project, ?Client $client, Context $context): array
    {
        self::primaryMarket($project);
        $lexicon = new Lexicon($project->setting('vertical'));
        $covered = self::labsCoverage($client, $context);
        $limit = (int) Config::get('research.suggestions_limit', 200);
        $competitorLimit = (int) Config::get('research.competitor_keywords_limit', 200);
        $competitors = array_slice(array_values(array_filter(array_map('strval', (array) $project->competitors))), 0, 3);
        $steps = [];

        foreach ($project->marketList() as $market) {
            $country = strtolower((string) $market['country']);
            $code = (int) $market['location_code'];

            foreach ((array) $market['languages'] as $lang) {
                $lang = (string) $lang;
                $seeds = self::seeds($project, $market, $lang, $lexicon);
                $suffix = $country . '_' . $lang;

                if (isset($covered[$code]) && in_array($lang, $covered[$code], true)) {
                    if ($seeds !== []) {
                        $steps[] = ['name' => 'kw_labs_' . $suffix, 'kind' => 'live', 'endpoint' => self::SUGGESTIONS, 'payload' => [
                            'tasks' => array_map(static fn ($seed) => [
                                'keyword' => $seed, 'location_code' => $code, 'language_code' => $lang,
                                'limit' => $limit, 'include_seed_keyword' => true,
                            ], $seeds),
                            'expect_items' => $limit,
                            'optional' => true,
                        ]];
                    }

                    if ($competitors !== []) {
                        $steps[] = ['name' => 'kw_comp_' . $suffix, 'kind' => 'live', 'endpoint' => self::RANKED, 'payload' => [
                            'tasks' => array_map(static fn ($domain) => [
                                'target' => $domain, 'location_code' => $code, 'language_code' => $lang, 'limit' => $competitorLimit,
                                'filters' => [['ranked_serp_element.serp_item.rank_group', '<=', 10]],
                            ], $competitors),
                            'expect_items' => $competitorLimit,
                            'optional' => true,
                        ]];
                    }

                    continue;
                }

                $candidates = self::candidates($market, $lang, $lexicon, $seeds);

                if ($candidates !== []) {
                    $steps = array_merge($steps, self::queued('kw_ads_' . $suffix, self::ADS_VOLUME, [[
                        'keywords' => array_slice($candidates, 0, 1000), 'location_code' => $code, 'language_code' => $lang,
                    ]]));
                }

                if ($seeds !== []) {
                    $steps = array_merge($steps, self::queued('kw_ideas_' . $suffix, self::ADS_IDEAS, [[
                        'keywords' => array_slice(array_map([self::class, 'adsSafe'], $seeds), 0, 20), 'location_code' => $code, 'language_code' => $lang,
                    ]]));
                }
            }
        }

        $primary = $project->primaryMarket();
        $local = (string) ($primary['languages'][0] ?? 'en');

        if (isset($covered[(int) $primary['location_code']]) && in_array($local, $covered[(int) $primary['location_code']], true)) {
            $steps[] = ['name' => 'own_ranked', 'kind' => 'live', 'endpoint' => self::RANKED, 'payload' => [
                'tasks' => [['target' => $project->host(), 'location_code' => (int) $primary['location_code'], 'language_code' => $local, 'limit' => 100]],
                'expect_items' => 100,
                'optional' => true,
            ]];
        }

        $origins = array_values(array_filter((array) $project->setting('origins', []), 'is_array'));
        $steps[] = ['name' => 'universe', 'kind' => 'analyse', 'payload' => ['analyser' => 'universe']];

        foreach ($origins as $i => $origin) {
            $steps[] = ['name' => 'kw_origin_' . $i, 'kind' => 'live', 'endpoint' => self::OVERVIEW, 'payload' => [
                'deferred' => true, 'estimate_tasks' => 1, 'expect_items' => 700, 'optional' => true, 'origin' => $origin,
            ]];
        }

        if ($origins !== []) {
            $steps[] = ['name' => 'universe_origin', 'kind' => 'analyse', 'payload' => ['analyser' => 'universe', 'origin' => true]];
        }

        $steps[] = ['name' => 'targets', 'kind' => 'analyse', 'payload' => ['analyser' => 'targets']];
        $steps[] = ['name' => 'priority', 'kind' => 'analyse', 'payload' => ['analyser' => 'priority']];

        return $steps;
    }

    /** @return array<int, array<string, mixed>> post → wait → fetch for a Keywords Data queue endpoint */
    private static function queued(string $name, string $base, array $tasks): array
    {
        return [
            ['name' => $name, 'kind' => 'post', 'endpoint' => $base . '/task_post', 'payload' => ['tasks' => $tasks]],
            ['name' => $name . '_wait', 'kind' => 'wait', 'payload' => ['of' => $name, 'probe_after' => 5]],
            ['name' => $name . '_fetch', 'kind' => 'fetch', 'endpoint' => $base . '/task_get', 'payload' => ['of' => $name]],
        ];
    }

    /**
     * Location code => language codes Labs has data for. Empty without a key
     * (then every language goes to Google Ads, which takes any keyword).
     *
     * @return array<int, string[]>
     */
    public static function labsCoverage(?Client $client, Context $context): array
    {
        if ($client === null) {
            return [];
        }

        try {
            $response = $client->call(self::LABS_LOCATIONS, null, $context);
        } catch (Throwable) {
            return [];
        }

        $covered = [];

        foreach ($response->items() as $location) {
            $code = (int) ($location['location_code'] ?? 0);

            foreach ((array) ($location['available_languages'] ?? []) as $language) {
                if ($code > 0 && isset($language['language_code'])) {
                    $covered[$code][] = (string) $language['language_code'];
                }
            }
        }

        return $covered;
    }

    /** @return string[] short seed phrases: type × place in this language, plus the manager's own */
    public static function seeds(Project $project, array $market, string $lang, Lexicon $lexicon): array
    {
        $seeds = [];

        foreach (self::places($market, $lang) as $place) {
            foreach ($lexicon->seedWords($lang) as $word) {
                $seeds[] = mb_strtolower($word . ' ' . $place);
            }
        }

        foreach ((array) $project->setting('seeds', []) as $seed) {
            $seed = trim((string) $seed);

            if ($seed !== '' && Text::detectLang($seed, (array) $market['languages'], $lexicon) === $lang) {
                $seeds[] = mb_strtolower($seed);
            }
        }

        return array_slice(array_values(array_unique($seeds)), 0, self::MAX_SEEDS);
    }

    /** @return string[] the first spelling of every place in this language (the name itself for the local language and English) */
    public static function places(array $market, string $lang): array
    {
        $places = [];
        $local = (string) ($market['languages'][0] ?? '');

        foreach ((array) ($market['geo'] ?? []) as $geo) {
            $spellings = (array) ($geo['spellings'][$lang] ?? []);

            if ($spellings !== []) {
                $places[] = (string) $spellings[0];
            } elseif ($lang === $local || $lang === 'en') {
                $places[] = (string) ($geo['name'] ?? '');
            }
        }

        return array_values(array_filter($places));
    }

    /**
     * Phrases Google Ads is asked the volume of: deal × type × every spelling
     * of every place, in the display words of this language.
     *
     * @return string[]
     */
    public static function candidates(array $market, string $lang, Lexicon $lexicon, array $seeds): array
    {
        $out = $seeds;

        foreach ((array) ($market['geo'] ?? []) as $geo) {
            $spellings = (array) ($geo['spellings'][$lang] ?? []);

            if ($spellings === [] && ($lang === 'en' || $lang === (string) ($market['languages'][0] ?? ''))) {
                $spellings = [(string) ($geo['name'] ?? '')];
            }

            foreach ($spellings as $place) {
                foreach ($lexicon->deals() as $deal) {
                    foreach ($lexicon->types() as $type) {
                        $out[] = trim(mb_strtolower($lexicon->word($lang, 'deal.' . $deal) . ' ' . $lexicon->word($lang, 'type.' . $type) . ' ' . $place));
                    }
                }

                foreach ($lexicon->seedWords($lang) as $word) {
                    $out[] = mb_strtolower($word . ' ' . $place);
                }
            }
        }

        return array_values(array_unique(array_filter(array_map([self::class, 'adsSafe'], $out))));
    }

    /** Google Ads refuses symbols, more than 10 words and more than 80 characters. */
    public static function adsSafe(string $phrase): string
    {
        $phrase = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[^\p{L}\p{N}\s\'-]+/u', ' ', $phrase)));
        $words = array_slice(explode(' ', $phrase), 0, 10);

        return mb_substr(implode(' ', $words), 0, 80);
    }
}
