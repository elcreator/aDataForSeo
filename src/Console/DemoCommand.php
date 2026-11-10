<?php

namespace Elcreator\aDataForSeo\Console;

use Carbon\Carbon;
use Elcreator\aDataForSeo\Api\DemoTransport;
use Elcreator\aDataForSeo\Engine\Slice;
use Elcreator\aDataForSeo\Engine\SliceResult;
use Elcreator\aDataForSeo\Models\Job;
use Elcreator\aDataForSeo\Models\Ranking;
use Elcreator\aDataForSeo\Models\Step;
use Elcreator\aDataForSeo\Services\Actor;
use Elcreator\aDataForSeo\Services\Jobs;
use Elcreator\aDataForSeo\Services\Projects;
use Elcreator\aDataForSeo\Services\WorkbenchException;
use Elcreator\aDataForSeo\Support\Keys;
use Elcreator\aDataForSeo\Support\UrlMap;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Seeds a self-contained demo: a real-estate project (Czechia: cs, en, uk;
 * the imaginary, public-domain cities Atlantis and Avalon; invented portals in
 * the reserved `.example` zone as competitors), a few landing pages, the offline demo key (or the free
 * DataForSEO sandbox with --sandbox and DATAFORSEO_LOGIN/PASSWORD), and with
 * --run every job kind run to the end right here. Safe to run repeatedly.
 */
class DemoCommand extends Command
{
    protected $signature = 'adfs:demo {--user= : Administrator user id (default: the first one)} {--documents : Create demo landing pages} {--run : Run every job now, synchronously} {--sandbox : Use the DataForSEO sandbox (DATAFORSEO_LOGIN / DATAFORSEO_PASSWORD) instead of offline data} {--domain= : The site address as visitors see it, e.g. localhost:8080 (a command cannot know it)}';

    protected $description = 'Seed an aDataForSeo demo project and, with --run, run its jobs';

    private const PAGES = [
        ['pagetitle' => 'Pronájem bytů Atlantida', 'alias' => 'pronajem-byty-atlantida'],
        ['pagetitle' => 'Prodej domů Avalon', 'alias' => 'prodej-domy-avalon'],
        ['pagetitle' => 'Apartments for rent in Atlantis', 'alias' => 'apartments-for-rent-atlantis'],
        ['pagetitle' => 'Kontakty', 'alias' => 'kontakty'],
        ['pagetitle' => 'O nás', 'alias' => 'o-nas'],
    ];

    public function handle(): int
    {
        $userId = (int) ($this->option('user') ?: DB::table('user_attributes')->where('role', 1)->orderBy('internalKey')->value('internalKey'));
        $actor = Actor::for($userId);

        if ($userId <= 0 || !$actor->mayManage() || !$actor->maySpend()) {
            $this->error('No administrator with adataforseo_manage and adataforseo_spend found. Pass --user=<id>.');

            return self::FAILURE;
        }

        if ($this->option('documents')) {
            $this->documents($userId);
        }

        $project = Projects::current();
        $domain = trim((string) ($this->option('domain') ?: getenv('ADFS_DEMO_DOMAIN') ?: ''));

        if ($domain !== '') {
            $project->domain = strtolower((string) preg_replace('#^[a-z]+://#', '', rtrim($domain, '/')));
        }

        $project->forceFill([
            'mode' => 'pro',
            'markets' => [[
                'country' => 'CZ', 'location_code' => 2203, 'languages' => ['cs', 'en', 'uk'],
                'geo' => [
                    ['name' => 'Atlantida', 'spellings' => ['cs' => ['Atlantida', 'Atlantidě'], 'en' => ['Atlantis'], 'uk' => ['Атлантида', 'Атлантиді']]],
                    ['name' => 'Avalon', 'spellings' => ['cs' => ['Avalon', 'Avalonu'], 'en' => ['Avalon'], 'uk' => ['Авалон', 'Авалоні']]],
                ],
            ]],
            'competitors' => [DemoTransport::PORTALS[0], DemoTransport::PORTALS[1]],
            'settings' => array_replace((array) ($project->settings ?? []), ['site_languages' => ['cs', 'en'], 'crawl_pages' => 100]),
            'monthly_cap' => 5.0,
            'job_cap' => 2.0,
            'approve_above' => 0.5,
        ])->save();

        if ($this->option('sandbox')) {
            $login = (string) getenv('DATAFORSEO_LOGIN');
            $password = (string) getenv('DATAFORSEO_PASSWORD');

            if ($login === '' || $password === '') {
                $this->error('--sandbox needs DATAFORSEO_LOGIN and DATAFORSEO_PASSWORD (a free DataForSEO registration).');

                return self::FAILURE;
            }

            Keys::save('site', null, $login, $password, 'sandbox');
        } else {
            Keys::save('site', null, 'demo', '', 'demo');
        }

        UrlMap::invalidate();
        $this->info('Demo project ready: ' . $project->domain . ' (' . ($this->option('sandbox') ? 'sandbox' : 'offline demo data') . ').');

        if ($this->option('run')) {
            foreach (['coverage', 'audit', 'research', 'difficulty', 'track', 'competitors'] as $kind) {
                $this->runJob($kind, $project, $actor);
            }

            $this->history($project);
        }

        return self::SUCCESS;
    }

    private function documents(int $userId): void
    {
        $now = time();

        foreach (self::PAGES as $page) {
            if (DB::table('site_content')->where('alias', $page['alias'])->exists()) {
                continue;
            }

            DB::table('site_content')->insert([
                'type' => 'document', 'contentType' => 'text/html', 'pagetitle' => $page['pagetitle'], 'longtitle' => '', 'description' => '',
                'alias' => $page['alias'], 'published' => 1, 'parent' => 0, 'isfolder' => 0,
                'introtext' => '', 'content' => '<p>' . $page['pagetitle'] . ' — demo page created by aDataForSeo. Offers, prices and photos would be listed here.</p>',
                'richtext' => 1, 'template' => (int) evo()->getConfig('default_template', 3), 'menuindex' => 0, 'searchable' => 1, 'cacheable' => 1,
                'createdby' => $userId, 'createdon' => $now, 'editedby' => $userId, 'editedon' => $now, 'publishedon' => $now, 'publishedby' => $userId,
                'deleted' => 0, 'hidemenu' => 0,
            ]);
        }

        try {
            evo()->clearCache('full');
        } catch (\Throwable) {
            // A fresh cache is a nicety for the demo.
        }

        $this->info('Demo pages ready.');
    }

    private function runJob(string $kind, $project, Actor $actor): void
    {
        try {
            $job = (new Jobs())->start($kind, $project, [], $actor, 'demo');
        } catch (WorkbenchException $e) {
            $this->warn($kind . ': ' . $e->getMessage());

            return;
        }

        if ($job->status === Job::AWAITING_APPROVAL) {
            $job = (new Jobs())->approve($job, $actor);
        }

        for ($i = 0; $i < 200; $i++) {
            $result = (new Slice())->run($job, 30.0);

            if ($result->status === SliceResult::WAITING) {
                // Demo tasks are ready at once; do not wait the real back-off.
                Step::query()->where('job_id', $job->getKey())->whereNotNull('next_check_at')->update(['next_check_at' => Carbon::now()->subSecond()]);
                $job->forceFill(['status' => Job::QUEUED])->save();

                continue;
            }

            if ($result->status === SliceResult::IDLE && $job->refresh()->status === Job::AWAITING_APPROVAL) {
                $job = (new Jobs())->approve($job, $actor);

                continue;
            }

            if ($result->status !== SliceResult::MORE) {
                break;
            }
        }

        $job->refresh();
        $this->line(sprintf('%-12s %-10s spent $%.4f', $kind, $job->status, (float) $job->spent) . ($job->error ? '  ' . $job->error : ''));
    }

    /** A week-old tracking run, so position changes have something to compare with. */
    private function history($project): void
    {
        $today = Carbon::today()->toDateString();
        $past = Carbon::today()->subDays(7)->toDateString();

        foreach (Ranking::query()->where('project_id', $project->getKey())->where('date', $today)->get() as $ranking) {
            if (Ranking::query()->where('project_id', $project->getKey())->where('keyword', $ranking->keyword)->where('date', $past)->exists()) {
                continue;
            }

            $shift = (crc32($ranking->keyword) % 9) - 4;
            Ranking::query()->create([
                'project_id' => $ranking->project_id, 'target_id' => $ranking->target_id, 'keyword_id' => $ranking->keyword_id,
                'keyword' => $ranking->keyword, 'date' => $past,
                'position' => $ranking->position === null ? (crc32($ranking->keyword) % 2 ? 18 : null) : max(1, $ranking->position + $shift),
                'url' => $ranking->url, 'serp_features' => $ranking->serp_features,
            ]);
        }
    }
}
