<?php

use Carbon\Carbon;
use Elcreator\aDataForSeo\Api\Client;
use Elcreator\aDataForSeo\Api\Pricing;
use Elcreator\aDataForSeo\Console\JobHandler;
use Elcreator\aDataForSeo\Console\TickCommand;
use Elcreator\aDataForSeo\Engine\Planner;
use Elcreator\aDataForSeo\Engine\Plans\Plan;
use Elcreator\aDataForSeo\Engine\Slice;
use Elcreator\aDataForSeo\Engine\SliceResult;
use Elcreator\aDataForSeo\Listeners\Pingback;
use Elcreator\aDataForSeo\Models\Job;
use Elcreator\aDataForSeo\Models\Step;
use Elcreator\aDataForSeo\Queue\Dispatcher;
use Elcreator\aDataForSeo\Queue\STaskQueue;
use Elcreator\aDataForSeo\Queue\SystemQueue;
use Elcreator\aDataForSeo\Services\Actor;
use Elcreator\aDataForSeo\Services\Jobs;
use Elcreator\aDataForSeo\Services\WorkbenchException;
use Elcreator\aDataForSeo\Support\EventLog;
use Elcreator\aDataForSeo\Support\Keys;
use EvolutionCMS\Models\SystemCliTask;

/**
 * Slices, both queues, waking, approval, permission re-checks and schedules.
 */

beforeEach(function () {
    adfsReset();
    config(['cms.settings.aDataForSeo.queue.driver' => 'system']);
});

/**
 * A SERP endpoint that is ready only after `$readyAfter` tasks_ready calls,
 * so "waiting" can be observed.
 */
function adfsSlowSerp(int $readyAfter = 1): AdfsFakeTransport
{
    $polls = 0;

    return new AdfsFakeTransport([
        '#task_post$#' => fn ($m, $p, $tasks) => adfsEnvelope(array_map(fn ($t, $i) => adfsTask([], $t, 20100, 0.0006, 'dfs-' . $i), $tasks, array_keys($tasks)), 0.0006 * count($tasks)),
        '#tasks_ready$#' => function () use (&$polls, $readyAfter) {
            $polls++;

            return adfsEnvelope([adfsTask($polls >= $readyAfter ? [['id' => 'dfs-0', 'tag' => 'x']] : [])]);
        },
        '#task_get#' => fn () => adfsEnvelope([adfsTask([['items' => [['type' => 'organic', 'rank_group' => 1, 'url' => 'https://example.test/', 'title' => 'Home']]]], ['keyword' => 'site:example.test', 'location_code' => 2203, 'language_code' => 'cs'])]),
    ]);
}

function adfsSerpJob(int $user, array $tasks = [['keyword' => 'byty atlantida', 'location_code' => 2203, 'language_code' => 'cs', 'depth' => 10]]): Job
{
    $project = adfsProject();
    $job = adfsJob($project, $user, 'custom');
    Planner::persist($job, Plan::serp('s', $tasks), null);

    return $job;
}

function adfsUseKey(): void
{
    Keys::save('site', null, 'login@example.test', 'secret', 'prod');
}

it('parks a job that waits for DataForSEO, with wake_at and no successor', function () {
    adfsUser(7);
    adfsUseKey();
    Client::useTransport(adfsSlowSerp(3));
    $job = adfsSerpJob(7);

    $result = (new Slice())->run($job, 30);

    expect($result->status)->toBe(SliceResult::WAITING)
        ->and($job->refresh()->status)->toBe(Job::WAITING)
        ->and($job->wake_at)->not->toBeNull()
        ->and($job->wake_at->isFuture())->toBeTrue()
        ->and(SystemCliTask::query()->count())->toBe(0);

    // Never sleeps: a second slice before wake_at does nothing and stays waiting.
    expect((new Slice())->run($job, 30)->status)->toBe(SliceResult::WAITING);
});

it('backs off between polls', function () {
    expect(\Elcreator\aDataForSeo\Engine\Steps\Wait::backoff(1))->toBe(60)
        ->and(\Elcreator\aDataForSeo\Engine\Steps\Wait::backoff(2))->toBe(120)
        ->and(\Elcreator\aDataForSeo\Engine\Steps\Wait::backoff(9))->toBe(300);
});

it('never pays twice: a re-run step keeps its posted ids, and a crashed post finds its tasks by tag', function () {
    adfsUser(7);
    adfsUseKey();
    $transport = adfsSlowSerp(1);
    Client::useTransport($transport);
    $job = adfsSerpJob(7);

    adfsDrive($job);
    expect($job->refresh()->status)->toBe(Job::DONE)->and($transport->count('#task_post$#'))->toBe(1);

    // Same step run again: no new post.
    $post = Step::query()->where('job_id', $job->id)->where('name', 's')->first();
    \Elcreator\aDataForSeo\Engine\Steps\StepRunner::for('post')->run($post, new \Elcreator\aDataForSeo\Engine\Runtime($job, $job->project, new Client(Keys::for(\Elcreator\aDataForSeo\Support\Principal::forUser(7))), new \Elcreator\aDataForSeo\Api\Context(1, $job->id)));
    expect($transport->count('#task_post$#'))->toBe(1);

    // A worker that died after paying: step left in `posting` without ids.
    $tag = $job->uuid . ':' . $post->id;
    $post->forceFill(['status' => Step::POSTING, 'dfs_task_ids' => [], 'updated_at' => now()])->save();
    $recover = new AdfsFakeTransport(['#tasks_ready$#' => fn () => adfsEnvelope([adfsTask([['id' => 'paid-1', 'tag' => $tag]])]), '#task_post$#' => fn () => adfsEnvelope([])]);
    Client::useTransport($recover);
    \Elcreator\aDataForSeo\Engine\Steps\StepRunner::for('post')->run($post->refresh(), new \Elcreator\aDataForSeo\Engine\Runtime($job, $job->project, new Client(Keys::for(\Elcreator\aDataForSeo\Support\Principal::forUser(7))), new \Elcreator\aDataForSeo\Api\Context(1, $job->id)));

    expect(array_keys($post->refresh()->dfs_task_ids))->toBe(['paid-1'])->and($recover->count('#task_post$#'))->toBe(0);
});

it('stops within its time budget and asks for a successor', function () {
    adfsUser(7);
    adfsUseKey();
    Client::useTransport(adfsSlowSerp(1));
    $job = adfsSerpJob(7);

    $result = (new Slice())->run($job, 0.0);

    expect($result->status)->toBe(SliceResult::MORE)->and($job->refresh()->status)->toBe(Job::QUEUED);
});

it('honours a cancel between steps', function () {
    adfsUser(7);
    adfsUseKey();
    Client::useTransport(adfsSlowSerp(1));
    $job = adfsSerpJob(7);
    (new Jobs())->cancel($job, Actor::for(7));

    expect((new Slice())->run($job, 30)->status)->toBe(SliceResult::FAILED)
        ->and($job->refresh()->status)->toBe(Job::CANCELLED)
        ->and(Step::query()->where('job_id', $job->id)->where('status', '!=', 'pending')->count())->toBe(0);
});

it('re-checks the job owner on every slice and fails the job when the right is gone', function () {
    adfsUser(7);
    adfsUseKey();
    Client::useTransport(adfsSlowSerp(1));
    $job = adfsSerpJob(7);
    adfsRevoke(7, 'adataforseo_spend');

    expect((new Slice())->run($job, 30)->status)->toBe(SliceResult::FAILED)
        ->and($job->refresh()->status)->toBe(Job::FAILED)
        ->and(implode("\n", EventLog::$lines))->toContain('job_user_lost_permission');
});

it('stops for approval when a later step has no price', function () {
    adfsUser(7);
    adfsUseKey();
    Client::useTransport(adfsSlowSerp(1));
    $job = adfsSerpJob(7, [['keyword' => 'site:example.test', 'location_code' => 2203, 'language_code' => 'cs', 'depth' => 100]]);

    expect((new Slice())->run($job, 30)->status)->toBe(SliceResult::IDLE)
        ->and($job->refresh()->status)->toBe(Job::AWAITING_APPROVAL);

    (new Jobs())->approve($job, Actor::for(7));
    expect(adfsDrive($job)->status)->toBe(Job::DONE);
});

describe('queues', function () {
    it('creates one CMS task per slice carrying ids only, and a successor only from a running slice', function () {
        adfsUser(7);
        $job = adfsJob(adfsProject(), 7);
        $queue = new SystemQueue();

        $queue->dispatch($job);
        $queue->dispatch($job);
        $task = SystemCliTask::query()->firstOrFail();

        expect(SystemCliTask::query()->count())->toBe(1)
            ->and($task->type)->toBe(JobHandler::TYPE)
            ->and($task->payload_json)->toHaveKeys(['job_id', 'job_uuid'])
            ->and(json_encode($task->payload_json))->not->toContain('secret')
            ->and($job->refresh()->queue)->toBe('system');

        $task->update(['status' => 'running']);
        $next = SystemQueue::enqueue($job, $task->id);
        expect($next->id)->not->toBe($task->id);
    });

    it('runs a slice through the CMS handler and queues no successor while waiting', function () {
        adfsUser(7);
        adfsUseKey();
        Client::useTransport(adfsSlowSerp(5));
        $job = adfsSerpJob(7);
        (new SystemQueue())->dispatch($job);
        $task = SystemCliTask::query()->firstOrFail();
        $task->update(['status' => 'running']);

        $result = (new JobHandler())->execute($task);

        expect($result['result']['status'])->toBe(Job::WAITING)
            ->and(SystemCliTask::query()->where('status', 'queued')->count())->toBe(0);
    });

    it('honours the CMS cancellation flag', function () {
        adfsUser(7);
        $job = adfsSerpJob(7);
        (new SystemQueue())->dispatch($job);
        $task = SystemCliTask::query()->firstOrFail();
        $task->update(['cancellation_requested_at' => now()]);

        (new JobHandler())->execute($task);

        expect($job->refresh()->status)->toBe(Job::CANCELLED);
    });

    it('lets the tick wake due jobs and re-queue abandoned ones', function () {
        adfsUser(7);
        $project = adfsProject();
        $due = adfsJob($project, 7, 'coverage', ['status' => Job::WAITING, 'wake_at' => Carbon::now()->subMinute()]);
        $later = adfsJob($project, 7, 'audit', ['status' => Job::WAITING, 'wake_at' => Carbon::now()->addHour()]);
        $dead = adfsJob($project, 7, 'research', ['status' => Job::RUNNING, 'heartbeat_at' => Carbon::now()->subHours(2)]);

        $command = new TickCommand();
        $output = new Symfony\Component\Console\Output\BufferedOutput();
        $command->setOutput(new Illuminate\Console\OutputStyle(new Symfony\Component\Console\Input\ArrayInput([]), $output));
        $command->handle();

        expect($output->fetch())->toContain('woken 1, re-queued 1')
            ->and($due->refresh()->status)->toBe(Job::QUEUED)
            ->and($later->refresh()->status)->toBe(Job::WAITING)
            ->and($dead->refresh()->status)->toBe(Job::QUEUED)
            ->and(SystemCliTask::query()->count())->toBe(2);
    });

    it('wakes a waiting job from a valid pingback token only', function () {
        adfsUser(7);
        $job = adfsJob(adfsProject(), 7, 'coverage', ['status' => Job::WAITING, 'wake_at' => Carbon::now()->addHour()]);

        expect(Pingback::wake(str_repeat('a', 64)))->toBeFalse()
            ->and($job->refresh()->status)->toBe(Job::WAITING)
            ->and(Pingback::wake(\Elcreator\aDataForSeo\Engine\Pingback::token($job->uuid)))->toBeTrue()
            ->and($job->refresh()->status)->toBe(Job::QUEUED)
            ->and(\Elcreator\aDataForSeo\Engine\Pingback::tokenFromPath('/adataforseo/ping/' . str_repeat('b', 64) . '?id=1'))->toBe(str_repeat('b', 64))
            ->and(\Elcreator\aDataForSeo\Engine\Pingback::tokenFromPath('/other/' . str_repeat('b', 64)))->toBeNull();
    });

    it('picks the CMS queue when sTask is not usable and reports the hints', function () {
        config(['cms.settings.aDataForSeo.queue.driver' => 'auto']);
        $expected = (new STaskQueue())->available() ? 'stask' : 'system';

        expect(Dispatcher::driver()->name())->toBe($expected)
            ->and(Dispatcher::status()['hints'][0]['command'])->toContain('artisan');
    });

    it('refuses a forced sTask queue that is not installed', function () {
        config(['cms.settings.aDataForSeo.queue.driver' => 'stask']);

        expect(fn () => Dispatcher::driver())->toThrow(RuntimeException::class);
    })->skip(fn () => (new STaskQueue())->available(), 'sTask is installed.');
});

describe('jobs', function () {
    it('needs approval above the threshold or for an unknown price, and starts cheap jobs at once', function () {
        adfsUser(7);
        adfsDemoKey();
        $project = adfsProject();

        $coverage = (new Jobs())->start('coverage', $project, [], Actor::for(7));
        expect($coverage->status)->toBe(Job::AWAITING_APPROVAL)->and($coverage->estimate)->toBeNull();

        $project->forceFill(['approve_above' => 0.10])->save();
        $audit = (new Jobs())->start('audit', $project, ['crawl_pages' => 1000], Actor::for(7));
        expect($audit->status)->toBe(Job::AWAITING_APPROVAL)->and($audit->estimate)->toBe(0.15);

        $project->forceFill(['approve_above' => 1.0])->save();
        $audit->update(['status' => Job::CANCELLED]);
        $cheap = (new Jobs())->start('audit', $project, ['crawl_pages' => 100], Actor::for(7));
        expect($cheap->status)->toBe(Job::QUEUED)->and(SystemCliTask::query()->count())->toBe(1);
    });

    it('refuses above the job cap, logs it, and leaves nothing behind', function () {
        adfsUser(7);
        adfsDemoKey();
        $project = adfsProject(['job_cap' => 0.01]);

        expect(fn () => (new Jobs())->start('audit', $project, ['crawl_pages' => 1000], Actor::for(7)))->toThrow(WorkbenchException::class)
            ->and(Job::query()->count())->toBe(0)
            ->and(implode("\n", EventLog::$lines))->toContain('refusal_job_cap');
    });

    it('requires adataforseo_spend to start or approve and refuses a second job of the same kind', function () {
        adfsUser(7);
        adfsUser(8, ['adataforseo']);
        adfsDemoKey();
        $project = adfsProject();

        expect(fn () => (new Jobs())->start('coverage', $project, [], Actor::for(8)))->toThrow(WorkbenchException::class);

        $job = (new Jobs())->start('coverage', $project, [], Actor::for(7));
        expect(fn () => (new Jobs())->approve($job, Actor::for(8)))->toThrow(WorkbenchException::class)
            ->and(fn () => (new Jobs())->start('coverage', $project, [], Actor::for(7)))->toThrow(WorkbenchException::class);
    });

    it('runs schedules as the manager who enabled them, and refuses once when that manager lost the right', function () {
        adfsUser(7, ['adataforseo', 'adataforseo_spend', 'adataforseo_manage']);
        adfsDemoKey();
        $project = adfsProject();
        (new \Elcreator\aDataForSeo\Services\Projects())->update($project, ['schedule' => ['audit' => 7], 'approve_above' => 5], Actor::for(7));

        expect($project->refresh()->authorized_by)->toBe(7)
            ->and((new Jobs())->schedules())->toBe(1)
            ->and(Job::query()->where('trigger', 'schedule')->value('user_id'))->toBe(7)
            ->and((new Jobs())->schedules())->toBe(0);

        Job::query()->delete();
        $schedule = $project->refresh()->schedule;
        $schedule['last']['audit'] = Carbon::now()->subDays(8)->toIso8601String();
        $project->forceFill(['schedule' => $schedule])->save();
        adfsRevoke(7, 'adataforseo_spend');
        EventLog::$lines = [];

        (new Jobs())->schedules();
        (new Jobs())->schedules();

        expect(Job::query()->count())->toBe(0)
            ->and(count(array_filter(EventLog::$lines, fn ($l) => str_contains($l, 'schedule_owner_lost_permission'))))->toBe(1);
    });

    it('never takes authorized_by from the request', function () {
        adfsUser(7, ['adataforseo', 'adataforseo_spend', 'adataforseo_manage']);
        $project = adfsProject();

        (new \Elcreator\aDataForSeo\Services\Projects())->update($project, ['authorized_by' => 99, 'mode' => 'pro'], Actor::for(7));

        expect($project->refresh()->authorized_by)->toBeNull();
    });

    it('retries a failed job from its failed step', function () {
        adfsUser(7);
        adfsUseKey();
        $job = adfsSerpJob(7);
        Client::useTransport(new AdfsFakeTransport(['#.#' => fn () => [401, '{"status_code":40100,"status_message":"no"}']]));
        expect(adfsDrive($job)->status)->toBe(Job::FAILED);

        Client::useTransport(adfsSlowSerp(1));
        (new Jobs())->retry($job, Actor::for(7));
        expect(adfsDrive($job)->status)->toBe(Job::DONE);
    });
});
