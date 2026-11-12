<?php

namespace Elcreator\aDataForSeo\Analysis\Gateway;

use Elcreator\aDataForSeo\Engine\Analyser;
use Elcreator\aDataForSeo\Engine\Runtime;
use Elcreator\aDataForSeo\Models\Keyword;
use Elcreator\aDataForSeo\Models\Step;
use Elcreator\aDataForSeo\Models\Target;
use Elcreator\aDataForSeo\Support\Lang;

/**
 * The gateway's difficulty answers (`items [{keyword, difficulty, nativeShare, serp}]`)
 * written onto the targets they belong to: `kd_serp`, `native_share` and, in
 * `serp_snapshot`, the counts that explain the score.
 *
 * The gateway names no ranking site, so a snapshot made here has `aggregates` and an empty
 * `results`; the page shows the counts instead of the top 10. `difficulty` null is "not
 * measurable" and stays null, never 0. A keyword the manager entered that no target uses is
 * kept in the job's result instead of being forced into the page matrix.
 */
final class DifficultyResult extends Analyser
{
    /** Most unmatched keywords kept in a job's result. */
    private const KEEP = 500;

    public function run(Runtime $rt, Step $step): array
    {
        $project = $rt->project;
        $checked = 0;
        $unmatched = [];
        $answered = false;

        foreach ((array) $step->param('waits', []) as $waitName) {
            $answer = $rt->gatewayResult((string) $waitName);
            $submit = $rt->step((string) $rt->step((string) $waitName)?->param('of'));

            if ($answer === null || $submit === null) {
                continue;
            }

            $answered = true;
            $body = (array) $submit->param('body', []);
            $country = strtoupper((string) ($body['location'] ?? ''));
            $lang = (string) ($body['language'] ?? '');
            $market = $project->market($country);

            foreach ((array) ($answer['items'] ?? []) as $item) {
                if (!is_array($item) || (string) ($item['keyword'] ?? '') === '') {
                    continue;
                }

                $keyword = mb_strtolower((string) $item['keyword']);
                $difficulty = is_numeric($item['difficulty'] ?? null) ? round((float) $item['difficulty'], 2) : null;
                $share = is_numeric($item['nativeShare'] ?? null) ? round((float) $item['nativeShare'], 4) : null;
                $aggregates = is_array($item['serp'] ?? null) ? $item['serp'] : null;
                $target = $market === null ? null : $this->targetOf($rt, (string) $market['country'], $lang, $keyword);

                if ($target === null) {
                    if (count($unmatched) < self::KEEP) {
                        $unmatched[] = ['keyword' => $keyword, 'difficulty' => $difficulty, 'native_share' => $share, 'serp' => $aggregates, 'country' => $country, 'lang' => $lang];
                    }

                    continue;
                }

                $target->forceFill([
                    'kd_serp' => $difficulty,
                    'native_share' => $share,
                    'serp_snapshot' => [
                        'keyword' => $keyword,
                        'checked_at' => now()->toIso8601String(),
                        'source' => 'gateway',
                        'data_fetched_at' => $answer['fetchedAt'] ?? null,
                        'optimistic' => false,
                        'features' => [],
                        'aggregates' => $aggregates,
                        'results' => [],
                    ],
                ])->save();
                $checked++;
            }
        }

        if (!$answered) {
            throw new \RuntimeException(Lang::get('aDataForSeo::global.error_gateway_expired'));
        }

        return ['output' => ['checked' => $checked, 'unmatched' => count($unmatched)], 'result' => ['difficulty' => ['checked' => $checked, 'items' => $unmatched]]];
    }

    private function targetOf(Runtime $rt, string $country, string $lang, string $keyword): ?Target
    {
        $ids = Keyword::query()->where('project_id', $rt->project->getKey())->where('market', $country)
            ->where('lang', $lang)->where('keyword', $keyword)->pluck('id')->all();

        return $ids === [] ? null : Target::query()->where('project_id', $rt->project->getKey())->whereIn('primary_keyword_id', $ids)->first();
    }
}
