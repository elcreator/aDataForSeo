<?php

namespace Elcreator\aDataForSeo\Http\Controllers;

use Elcreator\aDataForSeo\Models\Job;
use Elcreator\aDataForSeo\Services\Jobs;
use Elcreator\aDataForSeo\Services\WorkbenchException;
use Elcreator\aDataForSeo\Support\Lang;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Jobs: the list the page polls, one job with its steps, the estimate before
 * the click, and start / approve / cancel / retry.
 */
class JobController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request) {
            $query = Job::query()->where('project_id', $this->currentProject()->getKey());

            if ($this->flag($request, 'active')) {
                $query->whereIn('status', array_merge(Job::ACTIVE, [Job::AWAITING_APPROVAL]));
            }

            return ['jobs' => $query->orderByDesc('id')->limit(50)->get()->map(static fn (Job $j) => Jobs::present($j))->all()];
        });
    }

    public function show(string $uuid): JsonResponse
    {
        return $this->respond(fn () => ['job' => Jobs::present($this->find($uuid), true)]);
    }

    public function estimate(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request) {
            $result = (new Jobs())->estimate($this->text($request, 'kind'), $this->currentProject(), $this->listOf($request, 'options'), $this->actor());

            return ['estimate' => $result['estimate']->toArray(), 'steps' => $result['steps']];
        });
    }

    public function start(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request) {
            $job = (new Jobs())->start($this->text($request, 'kind'), $this->currentProject(), $this->listOf($request, 'options'), $this->actor());

            return ['job' => Jobs::present($job)];
        }, 'spend');
    }

    public function approve(string $uuid): JsonResponse
    {
        return $this->respond(fn () => ['job' => Jobs::present((new Jobs())->approve($this->find($uuid), $this->actor()))], 'spend');
    }

    public function cancel(string $uuid): JsonResponse
    {
        return $this->respond(fn () => ['job' => Jobs::present((new Jobs())->cancel($this->find($uuid), $this->actor()))], 'spend');
    }

    public function retry(string $uuid): JsonResponse
    {
        return $this->respond(fn () => ['job' => Jobs::present((new Jobs())->retry($this->find($uuid), $this->actor()))], 'spend');
    }

    private function find(string $uuid): Job
    {
        $job = Job::query()->where('project_id', $this->currentProject()->getKey())->where('uuid', trim($uuid))->first();

        if ($job === null) {
            throw WorkbenchException::notFound(Lang::get('aDataForSeo::global.what_job'));
        }

        return $job;
    }
}
