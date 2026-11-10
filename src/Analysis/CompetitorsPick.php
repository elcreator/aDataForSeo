<?php

namespace Elcreator\aDataForSeo\Analysis;

use Elcreator\aDataForSeo\Api\Response;
use Elcreator\aDataForSeo\Engine\Analyser;
use Elcreator\aDataForSeo\Engine\Runtime;
use Elcreator\aDataForSeo\Models\Step;

/**
 * The three domains that win this site's queries most (not itself, not
 * social networks), written into the deferred `relevant_pages` step. A
 * project without competitors of its own gets these.
 */
final class CompetitorsPick extends Analyser
{
    public function run(Runtime $rt, Step $step): array
    {
        $project = $rt->project;
        $market = $project->primaryMarket();
        $domains = [];

        foreach (Runtime::okTasks($rt->responses('serp_competitors')) as $task) {
            foreach (Response::itemsOf($task) as $item) {
                $domain = (string) preg_replace('/^www\./', '', strtolower((string) ($item['domain'] ?? '')));

                if ($domain === '' || Serp::sameSite($domain, $project->host()) || Serp::isUgc($domain)) {
                    continue;
                }

                $domains[$domain] = [
                    'domain' => $domain,
                    'etv' => (float) ($item['etv'] ?? 0),
                    'keywords' => (int) ($item['keywords_count'] ?? 0),
                    'avg_position' => isset($item['avg_position']) ? (float) $item['avg_position'] : null,
                    'visibility' => isset($item['visibility']) ? (float) $item['visibility'] : null,
                ];
            }
        }

        uasort($domains, static fn ($a, $b) => [$b['etv'], $b['keywords']] <=> [$a['etv'], $a['keywords']]);
        $top = array_slice(array_values($domains), 0, 3);

        if ((array) $project->competitors === [] && $top !== []) {
            $project->forceFill(['competitors' => array_column($top, 'domain')])->save();
        }

        return [
            'output' => ['competitors' => $top],
            'result' => ['competitors' => array_slice(array_values($domains), 0, 10)],
            'fill' => ['relevant_pages' => array_map(static fn ($c) => [
                'target' => $c['domain'],
                'location_code' => (int) $market['location_code'],
                'language_code' => (string) $market['languages'][0],
                'limit' => 50,
            ], $top)],
        ];
    }
}
