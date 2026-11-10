<?php

namespace Elcreator\aDataForSeo\Mcp\Tools;

use Elcreator\aDataForSeo\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('adataforseo.matrix')]
#[Description('Read-only. The page matrix: one target page per country, place, deal, type and language with real (deduplicated) demand, raw demand, SERP difficulty, priority score and tier, and the document serving it or the proposed URL.')]
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
            'country' => $schema->string()->description('Two-letter market, e.g. CZ'),
            'lang' => $schema->string()->description('Language code'),
            'tier' => $schema->string()->description('P1, P2 or P3'),
            'status' => $schema->string()->description('exists, not_indexed, missing or needs_locale'),
        ];
    }
}
