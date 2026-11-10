<?php

namespace Elcreator\aDataForSeo\Mcp;

use EvolutionCMS\eMCP\Contracts\ToolProvider as Contract;

/**
 * Offers search data to AI agents through eMCP, a soft dependency: the
 * provider registers this class only when eMCP exists. Mutating tools carry
 * eMCP's write marker and use the manager page's services.
 */
final class ToolProvider implements Contract
{
    public function server(): string
    {
        return (string) config('cms.settings.aDataForSeo.mcp.server', 'content');
    }

    public function tools(): array
    {
        return [
            Tools\CoverageTool::class, Tools\AuditTool::class, Tools\MatrixTool::class, Tools\SerpTool::class,
            Tools\RankingsTool::class, Tools\EstimateTool::class, Tools\ApplyFixTool::class,
            Tools\CreateDraftTool::class, Tools\StartJobTool::class, Tools\UndoChangeTool::class,
        ];
    }
}
