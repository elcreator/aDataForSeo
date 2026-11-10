<?php

namespace Elcreator\aDataForSeo\Mcp\Tools;

use Elcreator\aDataForSeo\Mcp\Tool;
use Elcreator\aDataForSeo\Models\Job;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('adataforseo.estimate')]
#[Description('Read-only. What a job would cost before it is started, in US dollars; amount null means unknown and needs a manager approval. Pass the same kind and options to adataforseo.start_job after reviewing the estimate.')]
class EstimateTool extends Tool
{
    protected function operation(): string
    {
        return 'estimate';
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
