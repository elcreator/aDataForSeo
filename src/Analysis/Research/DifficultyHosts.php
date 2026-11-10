<?php

namespace Elcreator\aDataForSeo\Analysis\Research;

use Elcreator\aDataForSeo\Analysis\Serp;
use Elcreator\aDataForSeo\Engine\Analyser;
use Elcreator\aDataForSeo\Engine\Runtime;
use Elcreator\aDataForSeo\Models\Step;

/**
 * The hosts in the fetched top 10s, grouped by market, written into the
 * deferred `traffic` step: Labs bulk traffic estimation, up to 1000 targets
 * per call, in each market's local-language database (the only one Labs has).
 */
final class DifficultyHosts extends Analyser
{
    public function run(Runtime $rt, Step $step): array
    {
        $hosts = [];

        foreach (Runtime::okTasks($rt->responses('serp_fetch')) as $task) {
            $location = (int) ($task['data']['location_code'] ?? 0);

            foreach (array_slice(Serp::organic($task), 0, 10) as $result) {
                if ($result['host'] !== null && !$result['ugc'] && !Serp::sameSite($result['host'], $rt->project->host())) {
                    $hosts[$location][$result['host']] = true;
                }
            }
        }

        $tasks = [];

        foreach ($hosts as $location => $set) {
            $market = Universe::marketOf($rt->project, (int) $location);

            if ($market === null) {
                continue;
            }

            foreach (array_chunk(array_keys($set), 1000) as $chunk) {
                $tasks[] = ['targets' => $chunk, 'location_code' => (int) $location, 'language_code' => (string) $market['languages'][0], 'item_types' => ['organic']];
            }
        }

        return ['output' => ['hosts' => array_sum(array_map('count', $hosts))], 'fill' => ['traffic' => $tasks]];
    }
}
