<?php

namespace Elcreator\aDataForSeo\Http\Controllers;

use Elcreator\aDataForSeo\Integrations\Sites;
use Elcreator\aDataForSeo\Integrations\SSeo;
use Elcreator\aDataForSeo\Models\Job;
use Elcreator\aDataForSeo\Queue\Dispatcher;
use Elcreator\aDataForSeo\Queue\STaskQueue;
use Elcreator\aDataForSeo\Services\Cards;
use Elcreator\aDataForSeo\Services\Jobs;
use Elcreator\aDataForSeo\Services\Projects;
use Elcreator\aDataForSeo\Support\Billing;
use Elcreator\aDataForSeo\Support\Keys;
use Illuminate\Http\JsonResponse;

/**
 * Everything the page needs on load, in one answer (docs/DESIGN.md → HTTP
 * contract): mode, project, markets, key (never the password), budget, queue,
 * flags, csrf_token.
 */
class BootstrapController extends Controller
{
    public function show(): JsonResponse
    {
        return $this->respond(function (): array {
            $actor = $this->actor();
            $project = $this->currentProject();
            $present = Projects::present($project);

            return [
                'mode' => $project->mode,
                'project' => $present,
                'markets' => $present['markets'],
                'billing' => $present['billing'],
                'currency' => Billing::currencyOf($project),
                'key' => Keys::describe($actor->principal(), $project),
                // Both ways to pay at once, so the account page can show the one that is not in use.
                'keys' => ['own' => Keys::describe($actor->principal()), 'gateway' => Keys::describeGateway($actor->principal())],
                'budget' => $present['budget'],
                'queue' => Dispatcher::status(),
                'flags' => [
                    'may_spend' => $actor->maySpend(),
                    'may_manage' => $actor->mayManage(),
                    'sseo' => SSeo::available(),
                    'slang' => Sites::hasSLang(),
                    'smultisite' => Sites::hasMultisite(),
                    'stask' => (new STaskQueue())->available(),
                ],
                'state' => Cards::state($project),
                'jobs' => Job::query()->where('project_id', $project->getKey())->orderByDesc('id')->limit(20)->get()->map(static fn (Job $j) => Jobs::present($j))->all(),
                'countries' => array_map(static fn ($c) => ['location_code' => $c[0], 'language' => $c[1]], Projects::COUNTRIES),
                'user_id' => $actor->userId(),
                'csrf_token' => $this->csrfToken(),
            ];
        });
    }
}
