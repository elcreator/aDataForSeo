<?php

namespace Elcreator\aDataForSeo\Analysis\Research;

use Elcreator\aDataForSeo\Analysis\Serp;
use Elcreator\aDataForSeo\Api\Response;
use Elcreator\aDataForSeo\Engine\Analyser;
use Elcreator\aDataForSeo\Engine\Runtime;
use Elcreator\aDataForSeo\Models\Keyword;
use Elcreator\aDataForSeo\Models\Step;
use Elcreator\aDataForSeo\Models\Target;

/**
 * How hard a target's top 10 is to break into, from the SERP itself.
 *
 * Each result's strength: UGC (social groups, video, forums) 15; a host with
 * known traffic min(100, 100·log10(etv+1)/6); unknown 10. For a page in a
 * language other than the market's own, a result whose title is not in that
 * language counts at half strength — a Polish portal answers a Russian query
 * poorly, and without this the uk/ru pages, the real opportunity on the
 * reference site, ranked below unreachable local head terms.
 * `kd_serp` = mean strength weighted by 1/log2(position+1).
 */
final class Difficulty extends Analyser
{
    public const UGC = 15.0;
    public const UNKNOWN = 10.0;

    public function run(Runtime $rt, Step $step): array
    {
        $project = $rt->project;
        $lexicon = new Lexicon($project->setting('vertical'));
        $traffic = self::traffic($rt->responses('traffic'));
        $checked = 0;

        foreach (Runtime::okTasks($rt->responses('serp_fetch')) as $task) {
            $data = (array) ($task['data'] ?? []);
            $market = Universe::marketOf($project, (int) ($data['location_code'] ?? 0));
            $lang = (string) ($data['language_code'] ?? '');
            $keyword = mb_strtolower((string) ($data['keyword'] ?? ''));

            if ($market === null) {
                continue;
            }

            $ids = Keyword::query()->where('project_id', $project->getKey())->where('market', $market['country'])
                ->where('lang', $lang)->where('keyword', $keyword)->pluck('id')->all();
            $target = $ids === [] ? null : Target::query()->where('project_id', $project->getKey())->whereIn('primary_keyword_id', $ids)->first();

            if ($target === null) {
                continue;
            }

            $local = (string) ($market['languages'][0] ?? '');
            $results = array_values(array_filter(Serp::organic($task), static fn ($r) => !Serp::sameSite($r['host'], $project->host())));
            $scored = self::score(array_slice($results, 0, 10), $traffic, $lang, $local, $lexicon, (array) $market['languages']);

            $target->forceFill([
                'kd_serp' => $scored['kd'],
                'native_share' => $scored['native_share'],
                'serp_snapshot' => [
                    'keyword' => $keyword,
                    'checked_at' => now()->toIso8601String(),
                    // Labs measures traffic in the local-language database: international sites look weaker than they are.
                    'optimistic' => $lang === 'en' && $local !== 'en',
                    'features' => Serp::features($task),
                    'results' => $scored['results'],
                ],
            ])->save();
            $checked++;
        }

        return ['output' => ['checked' => $checked], 'result' => ['difficulty' => ['checked' => $checked]]];
    }

    /** @return array<string, float> host => estimated organic traffic */
    public static function traffic(array $responses): array
    {
        $etv = [];

        foreach (Runtime::okTasks($responses) as $task) {
            foreach (Response::itemsOf($task) as $item) {
                $host = (string) preg_replace('/^www\./', '', strtolower((string) ($item['target'] ?? '')));

                if ($host !== '' && isset($item['metrics']['organic']['etv'])) {
                    $etv[$host] = (float) $item['metrics']['organic']['etv'];
                }
            }
        }

        return $etv;
    }

    public static function strength(array $result, array $traffic): float
    {
        if ($result['ugc']) {
            return self::UGC;
        }

        if ($result['host'] !== null && isset($traffic[$result['host']])) {
            return min(100.0, 100.0 * log10($traffic[$result['host']] + 1) / 6);
        }

        return self::UNKNOWN;
    }

    /**
     * @param array<int, array{position: int, host: ?string, title: string, url: ?string, ugc: bool}> $results
     * @return array{kd: ?float, native_share: ?float, results: array}
     */
    public static function score(array $results, array $traffic, string $lang, string $local, ?Lexicon $lexicon = null, array $languages = []): array
    {
        if ($results === []) {
            return ['kd' => null, 'native_share' => null, 'results' => []];
        }

        $sum = $weights = 0.0;
        $native = 0;
        $out = [];
        $candidates = array_values(array_unique(array_merge([$lang, $local], $languages)));

        foreach ($results as $result) {
            $titleLang = Text::detectLang($result['title'], $candidates, $lexicon);
            $strength = self::strength($result, $traffic);

            if ($titleLang === $lang) {
                $native++;
            } elseif ($lang !== $local) {
                $strength /= 2;
            }

            $weight = 1 / log(max(1, $result['position']) + 1, 2);
            $sum += $strength * $weight;
            $weights += $weight;
            $out[] = ['position' => $result['position'], 'host' => $result['host'], 'title' => $result['title'], 'url' => $result['url'], 'ugc' => $result['ugc'], 'strength' => round($strength, 1), 'lang' => $titleLang];
        }

        return [
            'kd' => round($sum / $weights, 2),
            'native_share' => round($native / count($results), 4),
            'results' => $out,
        ];
    }
}
