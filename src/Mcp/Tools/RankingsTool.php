<?php

namespace Elcreator\aDataForSeo\Mcp\Tools;

use Elcreator\aDataForSeo\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('adataforseo.rankings')]
#[IsReadOnly]
#[Description(
    'Read-only. Check which tracked keywords gained or lost Google positions for this site. '
    . 'Returns up to the 12 most recent stored tracking dates, oldest first, and one row per keyword. '
    . 'Delta is the previous position minus the latest position across the two newest dates. Positive means improvement. '
    . 'Delta is null if either position is unavailable. With no tracking data, dates and rows are empty. '
    . 'Use adataforseo.matrix to compare target demand and priority, or adataforseo.serp for the competitors on one target.'
)]
class RankingsTool extends Tool
{
    protected function operation(): string
    {
        return 'rankings';
    }

    /** @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'dates' => $schema->array()->items($schema->string())->required()->description('Tracking dates (YYYY-MM-DD) in ascending order.'),
            'rows' => $schema->array()->items($schema->object([
                'keyword' => $schema->string()->required(),
                'target_id' => $schema->integer()->nullable()->required(),
                'positions' => $schema->object()->required()->description('Recorded positions keyed by date. A null value means the site was outside the depth checked.'),
                'url' => $schema->string()->nullable()->required(),
                'features' => $schema->array()->items($schema->string())->nullable()->required(),
                'latest' => $schema->integer()->nullable()->required()->description('Position on the newest tracking date, if available.'),
                'delta' => $schema->integer()->nullable()->required()->description('Previous position minus latest position across the two newest dates.'),
            ]))->required(),
        ];
    }
}
