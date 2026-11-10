<?php

namespace Elcreator\aDataForSeo\Mcp\Tools;

use Elcreator\aDataForSeo\Mcp\Tool;
use EvolutionCMS\eMCP\Contracts\DestructiveSite;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('adataforseo.undo_change')]
#[Description('Changes the site. Undoes one recorded change with the manager page permission checks, only while the value still matches what was applied. Drafts can be removed only while unpublished and untouched. Returns the change with undone_at.')]
class UndoChangeTool extends Tool implements DestructiveSite
{
    protected function operation(): string
    {
        return 'undoChange';
    }

    /** @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'change' => $schema->integer()->required()->description('Change id returned by adataforseo.apply_fix or adataforseo.create_draft'),
        ];
    }
}
