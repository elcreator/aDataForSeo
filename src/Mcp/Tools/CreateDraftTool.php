<?php

namespace Elcreator\aDataForSeo\Mcp\Tools;

use Elcreator\aDataForSeo\Mcp\Tool;
use EvolutionCMS\eMCP\Contracts\WritesSite;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('adataforseo.create_draft')]
#[Description('Changes the site. Creates an unpublished document for a missing target page, under the chosen parent, with the manager page permission checks. Returns change ids for adataforseo.undo_change. Set preview to true to see the proposed title, alias and parent first.')]
class CreateDraftTool extends Tool implements WritesSite
{
    protected function operation(): string
    {
        return 'createDraft';
    }

    /** @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'target' => $schema->integer()->required()->description('Missing target id from adataforseo.matrix'),
            'parent' => $schema->integer()->min(0)->description('Parent document id; 0 (the default) creates at the site root'),
            'pagetitle' => $schema->string()->description('Override the generated page title'),
            'preview' => $schema->boolean()->description('Only show the changes'),
        ];
    }
}
