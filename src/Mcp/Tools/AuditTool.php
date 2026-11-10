<?php

namespace Elcreator\aDataForSeo\Mcp\Tools;

use Elcreator\aDataForSeo\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('adataforseo.audit')]
#[Description('Read-only. The findings of the latest coverage, audit and research runs as cards sorted by expected effect: what is wrong, why it matters, the fix code and up to 8 affected URLs with issue ids.')]
class AuditTool extends Tool
{
    protected function operation(): string
    {
        return 'audit';
    }

    /** @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
        ];
    }
}
