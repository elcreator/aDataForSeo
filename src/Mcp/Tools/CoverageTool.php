<?php

namespace Elcreator\aDataForSeo\Mcp\Tools;

use Elcreator\aDataForSeo\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('adataforseo.coverage')]
#[Description('Read-only. Index coverage of this site: pages in the sitemap vs pages Google returns for site:, and the list of pages that are not indexed, each mapped to its document.')]
class CoverageTool extends Tool
{
    protected function operation(): string
    {
        return 'coverage';
    }

    /** @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
        ];
    }
}
