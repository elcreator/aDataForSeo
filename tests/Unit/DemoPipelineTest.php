<?php

use Elcreator\aDataForSeo\Models\Call;
use Elcreator\aDataForSeo\Models\Issue;
use Elcreator\aDataForSeo\Models\Job;
use Elcreator\aDataForSeo\Models\Keyword;
use Elcreator\aDataForSeo\Models\Ranking;
use Elcreator\aDataForSeo\Models\Target;
use Elcreator\aDataForSeo\Services\Actor;
use Elcreator\aDataForSeo\Services\Jobs;

/**
 * Every job kind, end to end, against the offline demo transport: plan,
 * estimate, approval, post → wait → fetch → analyse, and the rows the cards
 * are built from. The demo answers in DataForSEO's real shapes, including the
 * goto wrappers and Ads buckets, so this is the closest to production the
 * suite gets without a network.
 */

beforeEach(function () {
    adfsReset();
    adfsAdmin(1);
    adfsDemoKey();
    adfsDocument('pronajem-byty-atlantida');
    adfsDocument('prodej-domy-avalon');
    adfsDocument('apartments-for-rent-atlantis');
    adfsDocument('kontakty');
});

function adfsRun(string $kind, $project): Job
{
    $jobs = new Jobs();
    $job = $jobs->start($kind, $project, [], Actor::for(1));

    if ($job->status === Job::AWAITING_APPROVAL) {
        $job = $jobs->approve($job, Actor::for(1));
    }

    $job = adfsDrive($job);

    for ($i = 0; $i < 5 && $job->status === Job::AWAITING_APPROVAL; $i++) {
        $job = adfsDrive($jobs->approve($job, Actor::for(1)));
    }

    return $job;
}

it('never names a real brand or a resolvable domain: invented portals in the reserved .example zone', function () {
    $project = adfsProject(['competitors' => []]);
    adfsRun('research', $project);
    adfsRun('difficulty', $project);
    adfsRun('competitors', $project);

    $hosts = [];

    foreach (\Elcreator\aDataForSeo\Models\Target::query()->whereNotNull('serp_snapshot')->get() as $target) {
        foreach ((array) ($target->serp_snapshot['results'] ?? []) as $row) {
            if ($row['host'] !== null) {
                $hosts[] = $row['host'];
            }
        }
    }

    foreach (Issue::query()->where('code', 'competitor_page')->get() as $issue) {
        $hosts[] = (string) parse_url($issue->url, PHP_URL_HOST);
    }

    $hosts = array_values(array_unique(array_map(static fn ($h) => preg_replace('/^www\./', '', $h), $hosts)));
    $real = array_filter($hosts, static fn ($h) => !str_ends_with($h, '.example') && $h !== 'example.test');

    expect($hosts)->not->toBeEmpty()->and(array_values($real))->toBe([])
        ->and($project->refresh()->competitors)->each->toEndWith('.example');
});

it('runs coverage: half the pages missing from site:, search pages indexed instead', function () {
    $project = adfsProject();
    $job = adfsRun('coverage', $project);

    expect($job->status)->toBe(Job::DONE, (string) $job->error)
        ->and($job->result['coverage']['sitemap'])->toBe(4)
        ->and(Issue::query()->where('code', 'indexed_not_in_sitemap')->count())->toBe(2)
        ->and(Issue::query()->where('code', 'sitemap_missing')->count())->toBe(1);

    // task_get answers repeat the task_post price: booked at 0.
    expect((float) Call::query()->where('endpoint', 'like', '%task_get%')->sum('cost'))->toBe(0.0)
        ->and((float) Call::query()->where('endpoint', 'like', '%task_post')->sum('cost'))->toBeGreaterThan(0.0)
        ->and($job->spent)->toBe(round((float) Call::query()->where('job_id', $job->id)->sum('cost'), 6));
});

it('runs the audit and maps findings to documents', function () {
    $project = adfsProject();
    $job = adfsRun('audit', $project);

    expect($job->status)->toBe(Job::DONE, (string) $job->error)
        ->and(Issue::query()->where('code', 'broken_page')->first()?->fix)->toBe('redirect')
        ->and(Issue::query()->where('source', 'audit')->whereNotNull('document_id')->count())->toBeGreaterThan(0)
        ->and(Issue::query()->where('code', 'redirect_chain')->count())->toBe(1);
});

it('runs research, difficulty, tracking and competitors in order', function () {
    $project = adfsProject();

    $research = adfsRun('research', $project);
    expect($research->status)->toBe(Job::DONE, (string) $research->error)
        ->and(Keyword::query()->count())->toBeGreaterThan(10)
        ->and(Target::query()->count())->toBeGreaterThan(2)
        ->and(Target::query()->whereNotNull('tier')->count())->toBeGreaterThan(0);

    // Ukrainian is not a Labs language in Czechia: it went to Google Ads.
    expect(Keyword::query()->where('lang', 'uk')->where('source', 'ads')->count())->toBeGreaterThan(0);

    // A matched landing page.
    expect(Target::query()->whereNotNull('document_id')->count())->toBeGreaterThan(0);

    $difficulty = adfsRun('difficulty', $project);
    expect($difficulty->status)->toBe(Job::DONE, (string) $difficulty->error)
        ->and(Target::query()->whereNotNull('kd_serp')->count())->toBeGreaterThan(0);

    $track = adfsRun('track', $project);
    expect($track->status)->toBe(Job::DONE, (string) $track->error)
        ->and(Ranking::query()->count())->toBeGreaterThan(0);

    $competitors = adfsRun('competitors', $project);
    expect($competitors->status)->toBe(Job::DONE, (string) $competitors->error)
        ->and(Issue::query()->where('code', 'competitor_page')->count())->toBeGreaterThan(0);
});
