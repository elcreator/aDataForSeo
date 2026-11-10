<?php

namespace Elcreator\aDataForSeo\Analysis;

use Carbon\Carbon;
use Elcreator\aDataForSeo\Analysis\Research\Universe;
use Elcreator\aDataForSeo\Engine\Analyser;
use Elcreator\aDataForSeo\Engine\Runtime;
use Elcreator\aDataForSeo\Models\Keyword;
use Elcreator\aDataForSeo\Models\Ranking;
use Elcreator\aDataForSeo\Models\Step;
use Elcreator\aDataForSeo\Models\Target;

/**
 * Positions from the fetched SERPs into `adfs_rankings`, with deltas.
 * The first run of a project raises no alerts: there is nothing to compare.
 */
final class Tracking extends Analyser
{
    public const DROP_ALERT = 5;

    public function run(Runtime $rt, Step $step): array
    {
        $project = $rt->project;
        $today = Carbon::today()->toDateString();
        $first = !Ranking::query()->where('project_id', $project->getKey())->where('date', '<', $today)->exists();
        $checked = $found = $top10 = 0;
        $positions = [];
        $alerts = [];

        foreach (Runtime::okTasks($rt->responses('rank_fetch')) as $task) {
            $data = (array) ($task['data'] ?? []);
            $market = Universe::marketOf($project, (int) ($data['location_code'] ?? 0));
            $keywordText = mb_strtolower((string) ($data['keyword'] ?? ''));

            if ($market === null || $keywordText === '') {
                continue;
            }

            $keyword = Keyword::query()->where('project_id', $project->getKey())->where('market', $market['country'])
                ->where('lang', (string) ($data['language_code'] ?? ''))->where('keyword', $keywordText)->where('origin', false)->first();
            $position = null;
            $url = null;

            foreach (Serp::organic($task) as $result) {
                if (Serp::sameSite($result['host'], $project->host())) {
                    $position = $result['position'];
                    $url = $result['url'];
                    break;
                }
            }

            $previous = Ranking::query()->where('project_id', $project->getKey())->where('keyword', $keywordText)
                ->where('date', '<', $today)->orderByDesc('date')->first();

            Ranking::query()->where('project_id', $project->getKey())->where('keyword', $keywordText)->where('date', $today)->delete();
            Ranking::query()->create([
                'project_id' => $project->getKey(),
                'target_id' => $keyword?->target_id,
                'keyword_id' => $keyword?->getKey(),
                'job_id' => $rt->job->getKey(),
                'keyword' => $keywordText,
                'date' => $today,
                'position' => $position,
                'url' => $url,
                'serp_features' => Serp::features($task),
            ]);

            $checked++;

            if ($position !== null) {
                $found++;
                $positions[] = $position;
                $top10 += $position <= 10 ? 1 : 0;
            }

            if (!$first && $previous !== null) {
                $before = $previous->position ?? 101;
                $now = $position ?? 101;

                if ($now - $before >= self::DROP_ALERT) {
                    $target = $keyword?->target_id ? Target::query()->find($keyword->target_id) : null;
                    $alerts[] = [
                        'code' => 'position_drop',
                        'severity' => $before <= 10 ? 'high' : 'medium',
                        'url' => $url ?? ($target?->url ?? $keywordText),
                        'document_id' => $target?->document_id,
                        'fix' => 'manual',
                        'details' => ['keyword' => $keywordText, 'before' => $previous->position, 'after' => $position],
                    ];
                }
            }
        }

        IssueBook::sync((int) $project->getKey(), (int) $rt->job->getKey(), 'tracking', $alerts);

        $summary = [
            'checked' => $checked,
            'found' => $found,
            'top10' => $top10,
            'average' => $positions === [] ? null : round(array_sum($positions) / count($positions), 1),
            'first_run' => $first,
            'alerts' => count($alerts),
        ];

        return ['output' => $summary, 'result' => ['tracking' => $summary]];
    }
}
