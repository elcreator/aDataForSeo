<?php

namespace Elcreator\aDataForSeo\Api;

use Carbon\Carbon;
use Elcreator\aDataForSeo\Models\Call;
use Elcreator\aDataForSeo\Models\Job;
use Elcreator\aDataForSeo\Models\Step;
use Elcreator\aDataForSeo\Support\EventLog;

/**
 * The money book: what was really charged, per call, from the response's own
 * `cost`. Budgets are checked against this, never against estimates.
 */
final class Ledger
{
    private const EPSILON = 0.0000005;

    /**
     * May a call with this estimate go ahead? Null when yes, else the refusal
     * code. Every refusal is written to the event log.
     */
    public function allow(Context $ctx, Estimate $estimate): ?string
    {
        $refusal = $this->check($ctx, $estimate);

        if ($refusal !== null) {
            EventLog::refusal('DataForSEO call', $refusal, [
                'project' => $ctx->projectId,
                'job' => $ctx->jobId,
                'estimate' => $estimate->ceiling(),
            ]);
        }

        return $refusal;
    }

    private function check(Context $ctx, Estimate $estimate): ?string
    {
        $amount = $estimate->ceiling();

        if ($amount === null) {
            if (!$ctx->approved) {
                return 'approval_needed';
            }

            // Approved blind: still never past a cap that is already spent.
            $amount = 0.0;
        } elseif ($amount <= 0) {
            return null;
        }

        if ($ctx->jobCap !== null && $ctx->jobId !== null) {
            $spent = $this->spentJob($ctx->jobId);

            if ($spent + $amount > $ctx->jobCap + self::EPSILON || ($estimate->isUnknown() && $spent >= $ctx->jobCap)) {
                return 'job_cap';
            }
        }

        if ($ctx->monthlyCap !== null && $ctx->projectId !== null) {
            $spent = $this->spentMonth($ctx->projectId, $ctx->currency);

            if ($spent + $amount > $ctx->monthlyCap + self::EPSILON || ($estimate->isUnknown() && $spent >= $ctx->monthlyCap)) {
                return 'monthly_cap';
            }
        }

        if ($ctx->balance !== null && $amount > $ctx->balance + self::EPSILON) {
            return 'balance';
        }

        return null;
    }

    /**
     * Write one answer to the book before it is used.
     *
     * @param array{currency?: string, env?: string, cache_key?: ?string, estimate?: ?float, cached?: bool, status_code?: int, tasks?: int, items?: int, depth?: ?int, response_path?: ?string, expires_at?: mixed} $meta
     */
    public function record(Context $ctx, string $endpoint, float $cost, array $meta = []): Call
    {
        $cost = max(0.0, round($cost, 6));

        $call = Call::query()->create([
            'project_id' => $ctx->projectId,
            'job_id' => $ctx->jobId,
            'step_id' => $ctx->stepId,
            'user_id' => $ctx->userId,
            'env' => (string) ($meta['env'] ?? 'prod'),
            'currency' => (string) ($meta['currency'] ?? $ctx->currency),
            'endpoint' => Endpoints::normalise($endpoint),
            'cache_key' => $meta['cache_key'] ?? null,
            'cost' => $cost,
            'estimate' => $meta['estimate'] ?? null,
            'cached' => (bool) ($meta['cached'] ?? false),
            'status_code' => (int) ($meta['status_code'] ?? 0),
            'tasks' => (int) ($meta['tasks'] ?? 0),
            'items' => (int) ($meta['items'] ?? 0),
            'depth' => $meta['depth'] ?? null,
            'response_path' => $meta['response_path'] ?? null,
            'fetched_at' => Carbon::now(),
            'expires_at' => $meta['expires_at'] ?? null,
        ]);

        if ($cost > 0) {
            if ($ctx->jobId !== null) {
                Job::query()->whereKey($ctx->jobId)->increment('spent', $cost);
            }

            if ($ctx->stepId !== null) {
                Step::query()->whereKey($ctx->stepId)->increment('cost', $cost);
            }
        }

        return $call;
    }

    /** Spend in one currency (null: every row, for callers that only ever had one). */
    public function spent(int $projectId, Carbon $from, ?Carbon $to = null, ?string $currency = null): float
    {
        return round((float) Call::query()
            ->where('project_id', $projectId)
            ->when($currency !== null, static fn ($q) => $q->where('currency', $currency))
            ->where('fetched_at', '>=', $from)
            ->when($to !== null, static fn ($q) => $q->where('fetched_at', '<', $to))
            ->sum('cost'), 6);
    }

    public function spentMonth(int $projectId, ?string $currency = null): float
    {
        return $this->spent($projectId, Carbon::now()->startOfMonth(), null, $currency);
    }

    public function spentJob(int $jobId): float
    {
        return round((float) Call::query()->where('job_id', $jobId)->sum('cost'), 6);
    }

    /**
     * What this endpoint really cost per task in the past (paid answers only),
     * for the calls whose item count or depth the price table cannot predict.
     *
     * @return array{avg: float, min: float, max: float, n: int}|null
     */
    public function history(string $endpoint, ?int $depth = null): ?array
    {
        $rows = Call::query()
            ->where('endpoint', Endpoints::normalise($endpoint))
            ->where('cached', false)
            ->where('cost', '>', 0)
            ->when($depth !== null, static fn ($q) => $q->where('depth', $depth))
            ->orderByDesc('id')
            ->limit(50)
            ->get(['cost', 'tasks']);

        if ($rows->isEmpty()) {
            return null;
        }

        $perTask = $rows->map(static fn (Call $c) => $c->cost / max(1, $c->tasks))->all();

        return [
            'avg' => array_sum($perTask) / count($perTask),
            'min' => min($perTask),
            'max' => max($perTask),
            'n' => count($perTask),
        ];
    }
}
