<?php

namespace Elcreator\aDataForSeo\Services;

use Elcreator\aDataForSeo\Api\Cache;
use Elcreator\aDataForSeo\Console\JobHandler;
use Elcreator\aDataForSeo\Models\Call;
use Elcreator\aDataForSeo\Models\Issue;
use Elcreator\aDataForSeo\Models\Job;
use Elcreator\aDataForSeo\Models\Keyword;
use Elcreator\aDataForSeo\Models\Ranking;
use Elcreator\aDataForSeo\Models\Step;
use Elcreator\aDataForSeo\Models\Target;
use Elcreator\aDataForSeo\Support\Config;
use Elcreator\aDataForSeo\Support\UrlMap;
use EvolutionCMS\Models\SystemCliTask;
use Throwable;

/**
 * Demo data is a way in, not something to keep: once a real DataForSEO login
 * is connected, everything the offline demo produced goes — its jobs, the
 * findings, matrix, keywords and rankings built from invented answers, its
 * ledger rows (so "spent this month" is real money only) and its stored
 * responses. Project settings and changes actually applied to the site stay:
 * those are the manager's, not the demo's.
 */
final class DemoData
{
    /** Anything left from the demo? */
    public static function present(): bool
    {
        return Call::query()->where('env', 'demo')->exists();
    }

    /** @return array{jobs: int, calls: int, rows: int} */
    public static function forget(): array
    {
        $jobIds = Call::query()->where('env', 'demo')->whereNotNull('job_id')->distinct()->pluck('job_id')->map(static fn ($id) => (int) $id)->all();
        $projectIds = Call::query()->where('env', 'demo')->whereNotNull('project_id')->distinct()->pluck('project_id')->map(static fn ($id) => (int) $id)->all();
        $jobs = Job::query()->whereIn('id', $jobIds ?: [0])->get();

        foreach ($jobs as $job) {
            self::removeTree(Config::storagePath('jobs/' . $job->uuid));

            try {
                SystemCliTask::query()->where('type', JobHandler::TYPE)->where('target', (string) $job->uuid)
                    ->whereIn('status', ['queued', 'picked', 'running'])->delete();
            } catch (Throwable) {
                // No CMS queue table here: nothing waits for these jobs.
            }
        }

        Step::query()->whereIn('job_id', $jobIds ?: [0])->delete();
        $jobCount = Job::query()->whereIn('id', $jobIds ?: [0])->delete();

        Call::query()->where('env', 'demo')->whereNotNull('response_path')->get()
            ->each(static fn (Call $call) => Cache::delete((string) $call->response_path));
        $calls = Call::query()->where('env', 'demo')->delete();

        $rows = 0;

        foreach ([Ranking::class, Keyword::class, Target::class, Issue::class] as $model) {
            $rows += $model::query()->whereIn('project_id', $projectIds ?: [0])->delete();
        }

        self::removeTree(Config::storagePath('demo'));
        // The demo's price snapshot must not stand in for the real price table.
        if (is_file(Config::storagePath('pricing.json'))) {
            @unlink(Config::storagePath('pricing.json'));
        }
        UrlMap::invalidate();

        return ['jobs' => $jobCount, 'calls' => $calls, 'rows' => $rows];
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
}
