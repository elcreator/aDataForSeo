<?php

namespace Elcreator\aDataForSeo\Mcp\Tools;

use Elcreator\aDataForSeo\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('adataforseo.rankings')]
#[Description('Read-only. Tracked positions of this site per keyword over the last runs, with the change since the previous run.')]
class RankingsTool extends Tool
{
    protected function operation(): string
    {
        return 'rankings';
    }

    /** @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
        ];
    }
}
