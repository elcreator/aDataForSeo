<?php

namespace Elcreator\aDataForSeo\Http\Controllers;

use Elcreator\aDataForSeo\Api\Client;
use Elcreator\aDataForSeo\Api\Endpoints;
use Elcreator\aDataForSeo\Services\Projects;
use Elcreator\aDataForSeo\Services\WorkbenchException;
use Elcreator\aDataForSeo\Support\Keys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The API explorer: any endpoint, a raw payload, the price before and the
 * cost after, the raw JSON back. Live calls run here, through the same
 * Estimator, Ledger and Cache as everything else; queued endpoints go
 * through a `custom` job. Raw data is shown because it comes from the site's
 * own DataForSEO account.
 */
class ExplorerController extends Controller
{
    public function estimate(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request) {
            [$client, $endpoint, $tasks] = $this->parse($request);

            return ['estimate' => $client->estimator()->estimate($endpoint, $tasks, (int) $request->input('priority', 1))->toArray(), 'queued' => Endpoints::isTaskPost($endpoint)];
        }, 'spend');
    }

    public function run(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request) {
            [$client, $endpoint, $tasks] = $this->parse($request);

            if (Endpoints::isTaskPost($endpoint)) {
                throw WorkbenchException::invalid('error_explorer_queued');
            }

            $project = $this->currentProject();
            $estimate = $client->estimator()->estimate($endpoint, $tasks);
            $caps = Projects::caps($project);
            $confirmed = $this->flag($request, 'confirm');

            if (!$confirmed && ($estimate->isUnknown() || $estimate->ceiling() > (float) $caps['approve_above'])) {
                return ['needs_confirmation' => true, 'estimate' => $estimate->toArray()];
            }

            $context = Projects::context($project, null, Keys::for($this->actor()->principal()))->approved($confirmed);
            $context = new \Elcreator\aDataForSeo\Api\Context($context->projectId, null, null, $this->actor()->userId(), null, $context->monthlyCap, $confirmed, 1, $context->balance);
            $response = $client->call($endpoint, $tasks, $context, ['cache' => !$this->flag($request, 'fresh')]);

            return ['estimate' => $estimate->toArray(), 'cost' => $response->recordedCost, 'cached' => $response->cached, 'response' => $response->raw()];
        }, 'spend');
    }

    /** @return array{0: Client, 1: string, 2: ?array} */
    private function parse(Request $request): array
    {
        if (!$this->actor()->mayManage() && $this->currentProject()->mode !== 'pro') {
            throw WorkbenchException::forbidden('explorer');
        }

        $endpoint = Endpoints::normalise($this->text($request, 'endpoint'));

        if (!preg_match('#^[a-z0-9_]+(/[a-z0-9_.-]+)+$#', $endpoint)) {
            throw WorkbenchException::invalid('error_endpoint');
        }

        $payload = $request->input('payload');

        if (is_string($payload)) {
            $payload = trim($payload) === '' ? null : json_decode($payload, true);

            if ($payload === null && trim((string) $request->input('payload')) !== '') {
                throw WorkbenchException::invalid('error_payload');
            }
        }

        if ($payload !== null && (!is_array($payload) || count($payload) > 100)) {
            throw WorkbenchException::invalid('error_payload');
        }

        if (is_array($payload) && !array_is_list($payload)) {
            $payload = [$payload];
        }

        $credentials = Keys::for($this->actor()->principal());

        if ($credentials === null) {
            throw WorkbenchException::invalid('error_no_key');
        }

        return [new Client($credentials), $endpoint, $payload];
    }
}
