<?php

namespace Elcreator\aDataForSeo\Mcp\Tools;

use Elcreator\aDataForSeo\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('adataforseo.matrix')]
#[IsReadOnly]
#[Description(
    'Read-only. Compare target pages and find gaps to prioritize. Each row represents one country, place, deal, type and language combination. '
    . 'Optional country, lang, tier and status filters match stored values and combine with AND. Omit all filters to include every target in the current project. '
    . 'Returns up to 1,000 targets ordered by descending priority score, then descending deduplicated demand, with unscored targets last. Totals cover the returned rows. '
    . 'Use a target id with adataforseo.serp to inspect the competition for one page, or use adataforseo.rankings to see position changes for this site.'
)]
class MatrixTool extends Tool
{
    protected function operation(): string
    {
        return 'matrix';
    }

    /** @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'country' => $schema->string()->description('Two-letter market code as stored, for example CZ. Omit for all markets.'),
            'lang' => $schema->string()->description('Target language code, for example cs. Omit for all languages.'),
            'tier' => $schema->string()->description('Priority tier P1, P2 or P3. Omit to include all tiers and unscored targets.'),
            'status' => $schema->string()->description('Page status: exists, not_indexed, missing or needs_locale. Omit for all statuses.'),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'targets' => $schema->array()->items(TargetSchema::object($schema))->required()
                ->description('Matching target pages. An empty list means there are no matches.'),
            'totals' => $schema->object([
                'targets' => $schema->integer()->required()->description('Number of returned targets.'),
                'demand_real' => $schema->integer()->required()->description('Sum of deduplicated demand.'),
                'demand_raw' => $schema->integer()->required()->description('Sum of raw demand.'),
                'ratio' => $schema->number()->nullable()->required()->description('Raw demand divided by deduplicated demand. Null when deduplicated demand is zero.'),
            ])->required(),
        ];
    }
}
