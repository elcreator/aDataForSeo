<?php

namespace Elcreator\aDataForSeo\Mcp\Tools;

use Elcreator\aDataForSeo\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('adataforseo.serp')]
#[Description('Read-only. One target page with its last checked Google top 10 (host, title, strength) and difficulty.')]
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
            'target' => $schema->integer()->required()->description('Target id from adataforseo.matrix'),
        ];
    }
}
