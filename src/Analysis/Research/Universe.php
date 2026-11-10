<?php

namespace Elcreator\aDataForSeo\Analysis\Research;

use Carbon\Carbon;
use Elcreator\aDataForSeo\Api\Response;
use Elcreator\aDataForSeo\Engine\Analyser;
use Elcreator\aDataForSeo\Engine\Runtime;
use Elcreator\aDataForSeo\Models\Keyword;
use Elcreator\aDataForSeo\Models\Project;
use Elcreator\aDataForSeo\Models\Step;

/**
 * Every keyword the research steps fetched, normalised into `adfs_keywords`.
 *
 * Labs answers nest the keyword (`keyword_info`, `keyword_properties`, and
 * for `ranked_keywords` one more level, `keyword_data`); Google Ads answers
 * are flat. The language of each keyword is guessed among the market's
 * languages — Labs in Czechia returns English queries too.
 *
 * Run once more with `origin`, it records origin demand (people abroad
 * searching for this market) as separate rows that never count as real demand.
 */
final class Universe extends Analyser
{
    public function run(Runtime $rt, Step $step): array
    {
        $project = $rt->project;
        $lexicon = new Lexicon($project->setting('vertical'));
        $origin = (bool) $step->param('origin', false);
        $saved = 0;

        foreach ($rt->stepsLike('kw_') as $source) {
            $isOrigin = str_starts_with($source->name, 'kw_origin');

            if ($isOrigin !== $origin || !in_array($source->kind, ['live', 'fetch'], true)) {
                continue;
            }

            $endpoint = $source->kind === 'fetch' ? (string) $rt->step((string) $source->param('of'))?->endpoint : (string) $source->endpoint;
            $sourceName = str_contains($endpoint, 'google_ads') ? 'ads' : 'labs';
            $originMarket = $isOrigin ? strtoupper((string) ($source->param('origin')['market'] ?? '')) : null;

            foreach (Runtime::okTasks(Runtime::responsesOf($source)) as $task) {
                $data = (array) ($task['data'] ?? []);
                $market = $originMarket !== null ? $project->market($originMarket) : self::marketOf($project, (int) ($data['location_code'] ?? 0));

                if ($market === null) {
                    continue;
                }

                $taskLang = (string) ($data['language_code'] ?? ($market['languages'][0] ?? 'en'));

                foreach (Response::itemsOf($task) as $item) {
                    $row = self::normalise($item, $sourceName);

                    if ($row === null) {
                        continue;
                    }

                    $lang = $origin ? $taskLang : (Text::detectLang($row['keyword'], (array) $market['languages'], $lexicon) ?? $taskLang);

                    if (!in_array($lang, (array) $market['languages'], true)) {
                        $lang = $taskLang;
                    }

                    self::save($project, (string) $market['country'], $lang, $row, $origin);
                    $saved++;
                }
            }
        }

        $fill = [];

        if (!$origin) {
            foreach ($rt->stepsLike('kw_origin_') as $originStep) {
                $spec = (array) $originStep->param('origin', []);
                $keywords = Keyword::query()->where('project_id', $project->getKey())->where('origin', false)
                    ->where('market', strtoupper((string) ($spec['market'] ?? '')))
                    ->where('lang', (string) ($spec['language_code'] ?? ''))
                    ->where('brand', false)
                    ->orderByDesc('volume')->limit(700)->pluck('keyword')->all();

                $fill[$originStep->name] = $keywords === [] ? [] : [[
                    'keywords' => $keywords,
                    'location_code' => (int) ($spec['location_code'] ?? 0),
                    'language_code' => (string) ($spec['language_code'] ?? ''),
                ]];
            }

            $own = Runtime::okTasks($rt->responses('own_ranked'))[0]['result'][0]['total_count'] ?? null;

            if ($own !== null) {
                $settings = (array) ($project->settings ?? []);
                $settings['ranked_keywords'] = (int) $own;
                $project->forceFill(['settings' => $settings])->save();
            }
        }

        $total = Keyword::query()->where('project_id', $project->getKey())->count();

        return [
            'output' => ['saved' => $saved, 'origin' => $origin],
            'fill' => $fill,
            'result' => $origin ? [] : ['research' => ['keywords' => $total, 'collected_at' => Carbon::now()->toIso8601String()]],
        ];
    }

    public static function marketOf(Project $project, int $locationCode): ?array
    {
        foreach ($project->marketList() as $market) {
            if ((int) ($market['location_code'] ?? 0) === $locationCode) {
                return $market;
            }
        }

        return null;
    }

    /** @return array{keyword: string, volume: ?int, monthly: ?array, kd_labs: ?int, core_keyword: ?string, cpc: ?float, competition: ?float, intent: ?string, source: string}|null */
    public static function normalise(array $item, string $source): ?array
    {
        $data = isset($item['keyword_data']) && is_array($item['keyword_data']) ? $item['keyword_data'] : $item;
        $keyword = mb_strtolower(trim((string) ($data['keyword'] ?? '')));

        if ($keyword === '' || mb_strlen($keyword) > 255) {
            return null;
        }

        $info = isset($data['keyword_info']) && is_array($data['keyword_info']) ? $data['keyword_info'] : $data;
        $properties = (array) ($data['keyword_properties'] ?? []);
        $competition = $info['competition'] ?? null;

        if (!is_numeric($competition)) {
            $competition = isset($info['competition_index']) && is_numeric($info['competition_index']) ? ((float) $info['competition_index']) / 100 : null;
        }

        $kd = $properties['keyword_difficulty'] ?? null;

        return [
            'keyword' => $keyword,
            'volume' => isset($info['search_volume']) && is_numeric($info['search_volume']) ? (int) $info['search_volume'] : null,
            'monthly' => self::vector((array) ($info['monthly_searches'] ?? [])),
            // `null` is unknown everywhere, never 0.
            'kd_labs' => is_numeric($kd) ? (int) $kd : null,
            'core_keyword' => isset($properties['core_keyword']) && is_string($properties['core_keyword']) ? mb_strtolower($properties['core_keyword']) : null,
            'cpc' => isset($info['cpc']) && is_numeric($info['cpc']) ? (float) $info['cpc'] : null,
            'competition' => $competition === null ? null : (float) $competition,
            'intent' => isset($data['search_intent_info']['main_intent']) ? (string) $data['search_intent_info']['main_intent'] : null,
            'source' => $source,
        ];
    }

    /** The last 12 monthly volumes, oldest first. @return int[]|null */
    public static function vector(array $monthly): ?array
    {
        $rows = array_values(array_filter($monthly, static fn ($m) => is_array($m) && isset($m['year'], $m['month'])));

        if ($rows === []) {
            return null;
        }

        usort($rows, static fn ($a, $b) => [(int) $a['year'], (int) $a['month']] <=> [(int) $b['year'], (int) $b['month']]);

        return array_map(static fn ($m) => (int) ($m['search_volume'] ?? 0), array_slice($rows, -12));
    }

    private static function save(Project $project, string $market, string $lang, array $row, bool $origin): void
    {
        $existing = Keyword::query()->where('project_id', $project->getKey())->where('market', $market)
            ->where('lang', $lang)->where('keyword', $row['keyword'])->where('origin', $origin)->first();

        $values = array_filter([
            'volume' => $row['volume'],
            'monthly' => $row['monthly'],
            'kd_labs' => $row['kd_labs'],
            'core_keyword' => $row['core_keyword'],
            'cpc' => $row['cpc'],
            'competition' => $row['competition'],
            'intent' => $row['intent'],
        ], static fn ($v) => $v !== null);

        if ($existing === null) {
            Keyword::query()->create($values + [
                'project_id' => $project->getKey(),
                'market' => $market,
                'lang' => $lang,
                'keyword' => $row['keyword'],
                'source' => $row['source'],
                'origin' => $origin,
                'fetched_at' => Carbon::now(),
            ]);

            return;
        }

        $existing->forceFill($values + ['fetched_at' => Carbon::now()])->save();
    }
}
