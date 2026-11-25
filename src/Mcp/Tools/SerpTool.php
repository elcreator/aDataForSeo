<?php

namespace Elcreator\aDataForSeo\Mcp\Tools;

use Elcreator\aDataForSeo\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('adataforseo.serp')]
#[IsReadOnly]
#[Description(
    'Read-only. Inspect the competition for one target from adataforseo.matrix. Returns the target and its last stored Google SERP snapshot. '
    . 'Check serp.checked_at for when the snapshot was recorded and data_fetched_at for the gateway data timestamp when available. '
    . 'Direct checks list up to 10 organic competitors. Gateway checks return aggregates with an empty results list. '
    . 'An unchecked target has serp set to null. An unknown target id returns an error. Use adataforseo.rankings to track positions for this site over time.'
)]
class SerpTool extends Tool
{
    protected function operation(): string
    {
        return 'serp';
    }

    /** @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'target' => $schema->integer()->required()->description('The id of a target row from adataforseo.matrix for the current project.'),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return ['target' => TargetSchema::object($schema)->required()];
    }
}
