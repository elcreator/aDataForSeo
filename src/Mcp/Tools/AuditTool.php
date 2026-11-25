<?php

namespace Elcreator\aDataForSeo\Mcp\Tools;

use Elcreator\aDataForSeo\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('adataforseo.audit')]
#[IsReadOnly]
#[Description(
    'Read-only. Start here to decide what to fix first. Returns saved open issue cards and high-priority page gaps sorted by expected effect. '
    . 'Each card explains the finding and suggests a fix. Issue cards give the total affected count and sample up to 8 URLs with issue ids. '
    . 'An empty cards list means there are no saved findings to show. Use adataforseo.coverage for sitemap and index counts or the list of unindexed pages. '
    . 'Pass an issue id to adataforseo.apply_fix with preview=true to inspect the proposed changes before applying them.'
)]
class AuditTool extends Tool
{
    protected function operation(): string
    {
        return 'audit';
    }

    /** @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return ['cards' => $schema->array()->items($schema->object([
            'type' => $schema->string()->enum(['issue', 'target'])->required(),
            'code' => $schema->string()->required(),
            'severity' => $schema->string()->required(),
            'count' => $schema->integer()->required(),
            'fix' => $schema->string()->required(),
            'effort' => $schema->string()->required(),
            'title' => $schema->string()->required(),
            'why' => $schema->string()->required(),
            'effect' => $schema->integer()->required(),
            'items' => $schema->array()->items($schema->object([
                'id' => $schema->integer()->required()->description('Issue id for adataforseo.apply_fix.'),
                'url' => $schema->string()->required(),
                'document_id' => $schema->integer()->nullable()->required(),
            ]))->description('Sample of affected URLs, present on issue cards.'),
            'target' => TargetSchema::object($schema)->description('Target page, present on page-gap cards.'),
        ]))->required()];
    }
}
