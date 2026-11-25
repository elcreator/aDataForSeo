<?php

namespace Elcreator\aDataForSeo\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;

final class TargetSchema
{
    public static function object(JsonSchema $schema)
    {
        return $schema->object([
            'id' => $schema->integer()->required(),
            'country' => $schema->string()->required(),
            'geo' => $schema->string()->required(),
            'deal' => $schema->string()->required(),
            'type' => $schema->string()->required(),
            'lang' => $schema->string()->required(),
            'document_id' => $schema->integer()->nullable()->required()->description('CMS document serving the target, when mapped.'),
            'url' => $schema->string()->nullable()->required(),
            'proposed_url' => $schema->string()->nullable()->required(),
            'primary_keyword' => $schema->string()->nullable()->required(),
            'demand_real' => $schema->integer()->nullable()->required()->description('Deduplicated search demand.'),
            'demand_raw' => $schema->integer()->nullable()->required()->description('Search demand before deduplication.'),
            'kd_serp' => $schema->number()->nullable()->required()
                ->description('Estimated difficulty of breaking into these results on a 0–100 scale. Direct checks weight competitor strength by rank.'),
            'difficulty' => $schema->string()->nullable()->required()
                ->description('Difficulty label derived from kd_serp: easy below 25, medium below 50, hard otherwise. Null when unmeasured.'),
            'score' => $schema->number()->nullable()->required()->description('Priority score.'),
            'tier' => $schema->string()->nullable()->required(),
            'status' => $schema->string()->required(),
            'serp' => $schema->object([
                'keyword' => $schema->string(),
                'checked_at' => $schema->string()->description('When the snapshot was recorded.'),
                'data_fetched_at' => $schema->string()->nullable()->description('When the gateway data was fetched, if available.'),
                'aggregates' => $schema->object()->nullable()->description('Summary counts from a gateway check.'),
                'results' => $schema->array()->items($schema->object([
                    'position' => $schema->integer(),
                    'host' => $schema->string()->nullable(),
                    'title' => $schema->string(),
                    'url' => $schema->string()->nullable(),
                    'strength' => $schema->number()->description('Estimated competitor strength on a 0–100 scale based on host traffic or a fallback, adjusted for language fit.'),
                ])),
            ])->nullable()->required()->description('Last saved SERP snapshot. Null when unchecked.'),
        ]);
    }
}
