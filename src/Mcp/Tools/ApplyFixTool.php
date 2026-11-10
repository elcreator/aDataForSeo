<?php

namespace Elcreator\aDataForSeo\Mcp\Tools;

use Elcreator\aDataForSeo\Mcp\Tool;
use EvolutionCMS\eMCP\Contracts\WritesSite;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('adataforseo.apply_fix')]
#[Description('Changes the site. Applies the fix of one finding (sSeo fields or a redirect), with the same permission checks as the manager page; every change is recorded and can be undone with adataforseo.undo_change. Set preview to true to see the changes first.')]
class ApplyFixTool extends Tool implements WritesSite
{
    protected function operation(): string
    {
        return 'applyFix';
    }

    /** @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'issue' => $schema->integer()->required()->description('Issue id from adataforseo.audit'),
            'fields' => $schema->object()->description('Override suggested sSeo values: meta_title, meta_description, meta_keywords, robots, canonical_url, exclude_from_sitemap'),
            'new_url' => $schema->string()->description('Redirect target, when the fix is a redirect'),
            'preview' => $schema->boolean()->description('Only show the changes'),
        ];
    }
}
