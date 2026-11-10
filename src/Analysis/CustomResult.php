<?php

namespace Elcreator\aDataForSeo\Analysis;

use Elcreator\aDataForSeo\Engine\Analyser;
use Elcreator\aDataForSeo\Engine\Runtime;
use Elcreator\aDataForSeo\Models\Step;

/**
 * The API explorer's queued request: nothing to analyse, only where the raw
 * answers are, so the page can show them.
 */
final class CustomResult extends Analyser
{
    public function run(Runtime $rt, Step $step): array
    {
        $source = $rt->step((string) $step->param('of'));
        $responses = $source === null ? [] : Runtime::responsesOf($source);
        $items = 0;

        foreach ($responses as $response) {
            $items += $response->itemCount();
        }

        return ['result' => ['custom' => ['responses' => count($responses), 'items' => $items, 'step' => $source?->name]]];
    }
}
