<?php

namespace Elcreator\aDataForSeo\Mcp\Tools;

use Elcreator\aDataForSeo\Mcp\Tool;
use Elcreator\aDataForSeo\Models\Job;
use EvolutionCMS\eMCP\Contracts\WritesSite;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('adataforseo.start_job')]
#[Description('Starts background work that can cost money. Review adataforseo.estimate with the same kind and options first. Requires adataforseo_spend and respects job and monthly caps; unknown or high estimates wait for approval in the module. Returns the job uuid, status, estimate and spent amount.')]
class StartJobTool extends Tool implements WritesSite
{
    protected function operation(): string
    {
        return 'startJob';
    }

    /** @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'kind' => $schema->string()->enum(Job::KINDS)->required()->description('Job kind'),
            'options' => $schema->object()->description('Job options: crawl_pages, javascript, priority, depth, recheck, keywords; custom jobs also accept endpoint, tasks and variant. Budgets and permissions cannot be overridden.'),
        ];
    }
}
