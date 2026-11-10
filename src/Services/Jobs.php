<?php

namespace Elcreator\aDataForSeo\Services;

use Carbon\Carbon;
use Elcreator\aDataForSeo\Api\Cache;
use Elcreator\aDataForSeo\Api\Client;
use Elcreator\aDataForSeo\Api\Estimate;
use Elcreator\aDataForSeo\Api\Ledger;
use Elcreator\aDataForSeo\Engine\Planner;
use Elcreator\aDataForSeo\Engine\Plans\Plan;
use Elcreator\aDataForSeo\Models\Call;
use Elcreator\aDataForSeo\Models\Job;
use Elcreator\aDataForSeo\Models\Project;
use Elcreator\aDataForSeo\Models\Step;
use Elcreator\aDataForSeo\Queue\Dispatcher;
use Elcreator\aDataForSeo\Queue\SystemQueue;
use Elcreator\aDataForSeo\Support\Config;
use Elcreator\aDataForSeo\Support\EventLog;
use Elcreator\aDataForSeo\Support\Keys;
use Elcreator\aDataForSeo\Support\Lang;
use Elcreator\aDataForSeo\Support\Principal;
use Throwable;

/**
 * Starting, approving, cancelling and retrying jobs, and the once-a-minute tick.
 *
 * A job is priced before it exists (`estimate()` builds the plan without
 * calling anything paid). Above `approve_above`, or when the price is unknown,
 * it waits in `awaiting_approval` for a manager with `adataforseo_spend`; above
 * a cap it is refused outright.
 */
final class Jobs
{
    public const OPTION_KEYS = ['crawl_pages', 'javascript', 'priority', 'depth', 'recheck', 'endpoint', 'tasks', 'variant', 'keywords'];

    /** @return array{estimate: Estimate, steps: array, plan: array} */
    public function estimate(string $kind, Project $project, array $options, Actor $actor): array
    {
        $actor->authorize();
        $draft = new Job(['kind' => $kind, 'project_id' => $project->getKey(), 'user_id' => $actor->userId(), 'options' => $this->options($kind, $options, $actor)]);
        [$client, $definitions] = $this->plan($draft, $project, $actor->principal());
        $estimator = $client?->estimator();
        $priority = (int) $draft->option('priority', 1);
        $steps = [];
        $estimates = [];

        foreach ($definitions as $definition) {
            $estimate = Planner::estimate($definition, $estimator, $priority);
            $estimates[] = $estimate;

            if (in_array($definition['kind'], ['post', 'live'], true)) {
                $steps[] = ['name' => $definition['name'], 'kind' => $definition['kind'], 'endpoint' => $definition['endpoint'] ?? '', 'tasks' => count((array) ($definition['payload']['tasks'] ?? [])), 'estimate' => $estimate->toArray()];
            }
        }

        return ['estimate' => Estimate::sum($estimates), 'steps' => $steps, 'plan' => $definitions];
    }

    /** @throws WorkbenchException */
    public function start(string $kind, Project $project, array $options, Actor $actor, string $trigger = 'manual'): Job
    {
        $actor->authorize('spend');

        if (!in_array($kind, Job::KINDS, true)) {
            throw WorkbenchException::invalid('error_kind');
        }

        if ($kind === 'custom' && !$actor->mayManage() && $project->mode !== 'pro') {
            throw WorkbenchException::forbidden('custom job');
        }

        $running = Job::query()->where('project_id', $project->getKey())->where('kind', $kind)
            ->whereIn('status', array_merge(Job::ACTIVE, [Job::AWAITING_APPROVAL]))->first();

        if ($running !== null && $kind !== 'custom') {
            throw WorkbenchException::refused('error_job_running', [], ['project' => $project->getKey(), 'kind' => $kind]);
        }

        $options = $this->options($kind, $options, $actor);
        $job = new Job(['project_id' => $project->getKey(), 'user_id' => $actor->userId(), 'kind' => $kind, 'trigger' => $trigger, 'options' => $options, 'status' => Job::DRAFT]);
        [$client, $definitions] = $this->plan($job, $project, $actor->principal());

        if ($client === null) {
            throw WorkbenchException::refused('error_no_key', [], ['project' => $project->getKey()]);
        }

        $job->save();
        $estimate = Planner::persist($job, $definitions, $client->estimator());
        $caps = Projects::caps($project);
        $ledger = new Ledger();
        $ceiling = $estimate->ceiling();

        if ($ceiling !== null && $ceiling > (float) $caps['job_cap'] + 1e-9) {
            $this->discard($job);

            throw WorkbenchException::refused('refusal_job_cap', ['amount' => self::money($ceiling), 'cap' => self::money((float) $caps['job_cap'])], ['kind' => $kind, 'estimate' => $ceiling]);
        }

        if ($ceiling !== null && $ledger->spentMonth((int) $project->getKey()) + $ceiling > (float) $caps['monthly_cap'] + 1e-9) {
            $this->discard($job);

            throw WorkbenchException::refused('refusal_monthly_cap', ['amount' => self::money($ceiling), 'cap' => self::money((float) $caps['monthly_cap'])], ['kind' => $kind, 'estimate' => $ceiling]);
        }

        $needsApproval = $ceiling === null || $ceiling > (float) $caps['approve_above'] + 1e-9;

        $job->forceFill([
            'estimate' => $estimate->amount,
            'estimate_high' => $estimate->high,
            'status' => $needsApproval ? Job::AWAITING_APPROVAL : Job::QUEUED,
            // Only a manager's click approves; a cheap job simply never needs it.
            'approved' => false,
        ])->save();

        if (!$needsApproval) {
            $this->dispatch($job);
        }

        return $job->refresh();
    }

    /** @throws WorkbenchException */
    public function approve(Job $job, Actor $actor): Job
    {
        $actor->authorize('spend');

        if ($job->status !== Job::AWAITING_APPROVAL) {
            throw WorkbenchException::refused('error_job_state', ['status' => $job->status], ['job' => $job->uuid]);
        }

        $job->forceFill(['approved' => true, 'approved_by' => $actor->userId(), 'status' => Job::QUEUED, 'error' => null])->save();
        $this->dispatch($job);

        return $job->refresh();
    }

    /** Cooperative: the worker stops before its next step. */
    public function cancel(Job $job, Actor $actor): Job
    {
        $actor->authorize('spend');

        if (!$job->isFinished()) {
            $this->markCancelled($job);
        }

        return $job->refresh();
    }

    public function markCancelled(Job $job): void
    {
        $job->forceFill(['status' => Job::CANCELLED, 'finished_at' => Carbon::now(), 'wake_at' => null])->save();
        SystemQueue::cancel($job);
    }

    /** A failed or cancelled job again from its failed step; what was fetched is kept. */
    public function retry(Job $job, Actor $actor): Job
    {
        $actor->authorize('spend');

        if (!in_array($job->status, [Job::FAILED, Job::CANCELLED], true)) {
            throw WorkbenchException::refused('error_job_state', ['status' => $job->status], ['job' => $job->uuid]);
        }

        Step::query()->where('job_id', $job->getKey())->whereIn('status', [Step::FAILED, Step::WAITING, Step::POSTING])
            ->update(['status' => Step::PENDING, 'attempts' => 0, 'error' => null, 'next_check_at' => null]);

        $job->forceFill(['status' => Job::QUEUED, 'error' => null, 'finished_at' => null, 'user_id' => $actor->userId()])->save();
        $this->dispatch($job);

        return $job->refresh();
    }

    public function dispatch(Job $job): void
    {
        try {
            Dispatcher::dispatch($job);
        } catch (Throwable $e) {
            $job->forceFill(['error' => mb_substr($e->getMessage(), 0, 500)])->save();
        }
    }

    /**
     * The once-a-minute heartbeat: wake jobs whose DataForSEO tasks should be
     * ready, re-queue jobs whose worker died, start scheduled jobs, purge old
     * files and rows, and reconcile the ledger with the balance once a day.
     *
     * @return array{woken: int, reaped: int, scheduled: int, purged: int}
     */
    public function tick(): array
    {
        $now = Carbon::now();
        $woken = $reaped = 0;

        foreach (Job::query()->where('status', Job::WAITING)->where('wake_at', '<=', $now)->limit(100)->get() as $job) {
            $job->forceFill(['status' => Job::QUEUED])->save();
            $this->dispatch($job);
            $woken++;
        }

        $stale = $now->copy()->subSeconds(max(60, (int) Config::limit('stale_after', 900)));

        foreach (Job::query()->whereIn('status', [Job::RUNNING, Job::QUEUED])
            ->where(static fn ($q) => $q->where('heartbeat_at', '<', $stale)->orWhere(static fn ($q) => $q->whereNull('heartbeat_at')->where('updated_at', '<', $stale)))
            ->limit(50)->get() as $job) {
            $job->forceFill(['status' => Job::QUEUED, 'heartbeat_at' => $now])->save();
            $this->dispatch($job);
            $reaped++;
        }

        return [
            'woken' => $woken,
            'reaped' => $reaped,
            'scheduled' => $this->schedules(),
            'purged' => $this->purge(),
        ] + ['reconciled' => $this->reconcile()];
    }

    /** Scheduled jobs run as the manager who enabled the schedule, re-checked now. */
    public function schedules(): int
    {
        $started = 0;

        foreach (Project::query()->get() as $project) {
            $schedule = (array) ($project->schedule ?? []);
            $last = (array) ($schedule['last'] ?? []);
            $due = [];

            foreach (['coverage', 'audit', 'research', 'track'] as $kind) {
                $days = (int) ($schedule[$kind] ?? 0);

                if ($days > 0 && (!isset($last[$kind]) || Carbon::parse($last[$kind])->addDays($days)->isPast())) {
                    $due[] = $kind;
                }
            }

            if ($due === []) {
                continue;
            }

            $userId = (int) $project->authorized_by;

            if ($userId <= 0 || !Principal::forUser($userId)->maySpend()) {
                if (empty($schedule['refused_logged'])) {
                    EventLog::refusal('scheduled jobs of project ' . $project->getKey(), 'schedule_owner_lost_permission', ['user' => $userId]);
                    $schedule['refused_logged'] = true;
                    $project->forceFill(['schedule' => $schedule])->save();
                }

                continue;
            }

            foreach ($due as $kind) {
                $last[$kind] = Carbon::now()->toIso8601String();

                try {
                    $this->start($kind, $project, [], Actor::for($userId), 'schedule');
                    $started++;
                } catch (WorkbenchException $e) {
                    EventLog::write('Scheduled ' . $kind . ' not started: ' . $e->getMessage());
                }
            }

            $schedule['last'] = $last;
            $project->forceFill(['schedule' => $schedule])->save();
        }

        return $started;
    }

    /** Expired cache files, old job files and ledger rows past `limits.history_days`. */
    public function purge(): int
    {
        $purged = 0;
        $now = Carbon::now();

        Call::query()->whereNotNull('response_path')->where('expires_at', '<', $now)->limit(500)->get()
            ->each(static function (Call $call) use (&$purged) {
                Cache::delete((string) $call->response_path);
                $call->forceFill(['response_path' => null])->save();
                $purged++;
            });

        $cutoff = $now->copy()->subDays(max(7, (int) Config::limit('history_days', 180)));
        $purged += Call::query()->where('created_at', '<', $cutoff)->delete();

        foreach (Job::query()->whereIn('status', Job::FINISHED)->where('finished_at', '<', $cutoff)->limit(50)->get() as $job) {
            self::removeTree(Config::storagePath('jobs/' . $job->uuid));
            Step::query()->where('job_id', $job->getKey())->delete();
            $job->delete();
            $purged++;
        }

        return $purged;
    }

    /**
     * Once a day: compare what the ledger booked with how far the balance
     * moved, and log a drift above `budget.drift_alert`.
     */
    public function reconcile(): bool
    {
        $file = Config::storagePath('reconcile.json');
        $state = is_file($file) ? (array) json_decode((string) @file_get_contents($file), true) : [];

        if (isset($state['at']) && Carbon::parse($state['at'])->addDay()->isFuture()) {
            return false;
        }

        $key = Keys::siteKey();
        $credentials = $key === null ? null : Keys::credentialsOf($key);

        if ($credentials === null || $credentials->env !== 'prod') {
            return false;
        }

        try {
            $balance = Keys::verify($credentials, $key)['balance'];
        } catch (Throwable) {
            return false;
        }

        $booked = (float) Call::query()->where('env', 'prod')->where('created_at', '>=', $state['at'] ?? Carbon::now()->subDay())->sum('cost');

        if (isset($state['balance']) && $balance !== null) {
            $drift = abs(((float) $state['balance'] - $balance) - $booked);

            if ($drift > Config::budget('drift_alert', 0.05)) {
                EventLog::write(sprintf('Ledger drift: balance moved %.4f, ledger booked %.4f (drift %.4f). Payments in between also move the balance.', (float) $state['balance'] - $balance, $booked, $drift));
            }
        }

        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0775, true);
        }

        @file_put_contents($file, json_encode(['at' => Carbon::now()->toIso8601String(), 'balance' => $balance]));

        return true;
    }

    /** @return array{0: ?Client, 1: array} */
    private function plan(Job $job, Project $project, Principal $principal): array
    {
        $credentials = Keys::for($principal);
        $client = $credentials === null ? null : new Client($credentials);

        return [$client, Plan::for((string) $job->kind)->build($job, $project, $client, Projects::context($project, null, $credentials))];
    }

    private function options(string $kind, array $options, Actor $actor): array
    {
        $clean = array_intersect_key($options, array_flip(self::OPTION_KEYS));

        // Doubling the queue price is a professional's choice.
        if (isset($clean['priority'])) {
            $clean['priority'] = $actor->mayManage() ? max(1, min(2, (int) $clean['priority'])) : 1;
        }

        if (isset($clean['crawl_pages'])) {
            $clean['crawl_pages'] = max(1, min(10000, (int) $clean['crawl_pages']));
        }

        if (isset($clean['depth'])) {
            $clean['depth'] = max(10, min(100, (int) $clean['depth']));
        }

        foreach (['javascript', 'recheck'] as $flag) {
            if (isset($clean[$flag])) {
                $clean[$flag] = filter_var($clean[$flag], FILTER_VALIDATE_BOOLEAN);
            }
        }

        if ($kind !== 'custom') {
            unset($clean['endpoint'], $clean['tasks'], $clean['variant']);
        }

        return $clean;
    }

    private function discard(Job $job): void
    {
        Step::query()->where('job_id', $job->getKey())->delete();
        $job->delete();
    }

    public static function money(?float $amount): string
    {
        return $amount === null ? '?' : '$' . number_format($amount, $amount < 0.1 ? 4 : 2);
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            is_dir($path . '/' . $entry) ? self::removeTree($path . '/' . $entry) : @unlink($path . '/' . $entry);
        }

        @rmdir($path);
    }

    public static function present(Job $job, bool $steps = false): array
    {
        $out = [
            'uuid' => $job->uuid,
            'kind' => $job->kind,
            'kind_label' => Lang::get('aDataForSeo::global.kind_' . $job->kind),
            'status' => $job->status,
            'phase' => $job->phase,
            'progress' => (int) $job->progress,
            'estimate' => $job->estimate,
            'estimate_high' => $job->estimate_high,
            'spent' => round((float) $job->spent, 6),
            'trigger' => $job->trigger,
            'queue' => $job->queue,
            'wake_at' => $job->wake_at?->toIso8601String(),
            'error' => $job->error,
            'result' => (array) ($job->result ?? []),
            'options' => array_diff_key((array) ($job->options ?? []), ['tasks' => true]),
            'user_id' => (int) $job->user_id,
            'active' => $job->isActive(),
            'finished' => $job->isFinished(),
            'created_at' => $job->created_at?->toIso8601String(),
            'finished_at' => $job->finished_at?->toIso8601String(),
        ];

        if ($steps) {
            $out['steps'] = Step::query()->where('job_id', $job->getKey())->orderBy('seq')->get()->map(static fn (Step $s) => [
                'name' => $s->name, 'kind' => $s->kind, 'endpoint' => $s->endpoint, 'status' => $s->status,
                'estimate' => $s->estimate, 'cost' => round((float) $s->cost, 6), 'error' => $s->error,
                'tasks' => count((array) $s->dfs_task_ids) ?: count((array) $s->param('tasks', [])),
                'next_check_at' => $s->next_check_at?->toIso8601String(),
            ])->all();
        }

        return $out;
    }
}
