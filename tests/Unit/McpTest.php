<?php

use Elcreator\aDataForSeo\Integrations\SSeo;
use Elcreator\aDataForSeo\Mcp\Operations;
use Elcreator\aDataForSeo\Models\Call;
use Elcreator\aDataForSeo\Models\Change;
use Elcreator\aDataForSeo\Models\Issue;
use Elcreator\aDataForSeo\Models\Job;
use Elcreator\aDataForSeo\Models\Target;
use Elcreator\aDataForSeo\Services\Actor;
use Elcreator\aDataForSeo\Services\WorkbenchException;
use Elcreator\aDataForSeo\Support\EventLog;
use Illuminate\Database\Capsule\Manager as Capsule;

beforeEach(function () {
    adfsReset();
    $this->project = adfsProject();
    $this->seo = new AdfsFakeSeo();
    SSeo::fake($this->seo);
});

function adfsMcpIssue($project, int $document): Issue
{
    return Issue::query()->create([
        'project_id' => $project->id, 'source' => 'audit', 'code' => 'empty_title', 'severity' => 'high',
        'url' => 'https://example.test/page', 'url_hash' => sha1(uniqid()), 'document_id' => $document,
        'fix' => 'seo_fields', 'details' => ['suggest' => ['meta_title' => 'Suggested']], 'status' => Issue::OPEN,
    ]);
}

function adfsMcpTarget($project): Target
{
    return Target::query()->create([
        'project_id' => $project->id, 'country' => 'CZ', 'geo' => 'Avalon', 'deal' => 'sale', 'type' => 'house',
        'lang' => 'en', 'proposed_url' => 'https://example.test/en/houses-for-sale/avalon', 'status' => 'missing',
    ]);
}

it('previews and applies the same overridden fields and undoes the recorded change', function () {
    adfsUser(5, ['adataforseo', 'save_document']);
    $doc = adfsDocument('page');
    $this->seo->fields[$doc]['base']['meta_title'] = 'Old';
    $issue = adfsMcpIssue($this->project, $doc);
    $args = ['issue' => $issue->id, 'fields' => ['meta_title' => 'Chosen title']];

    $preview = Operations::applyFix(Actor::for(5), $args + ['preview' => true]);
    expect($preview['changes'][0])->toMatchArray(['before' => 'Old', 'after' => 'Chosen title'])
        ->and($this->seo->writes)->toBe(0)
        ->and(Change::query()->count())->toBe(0);

    $applied = Operations::applyFix(Actor::for(5), $args);
    expect($applied['changes'][0])->toMatchArray(['before' => 'Old', 'after' => 'Chosen title', 'user_id' => 5])
        ->and($this->seo->fields[$doc]['base']['meta_title'])->toBe('Chosen title');

    $undone = Operations::undoChange(Actor::for(5), ['change' => $applied['changes'][0]['id']]);
    expect($undone['change']['undone_at'])->not->toBeNull()
        ->and($this->seo->fields[$doc]['base']['meta_title'])->toBe('Old')
        ->and($issue->refresh()->status)->toBe(Issue::OPEN);
});

it('previews redirect overrides even when the finding has no suggested target', function () {
    adfsUser(5, ['adataforseo', 'exec_module']);
    $issue = Issue::query()->create([
        'project_id' => $this->project->id, 'source' => 'audit', 'code' => 'broken_page', 'severity' => 'high',
        'url' => 'https://example.test/gone', 'url_hash' => 'gone', 'fix' => 'redirect', 'status' => Issue::OPEN,
    ]);
    $args = ['issue' => $issue->id, 'new_url' => '/replacement'];

    $preview = Operations::applyFix(Actor::for(5), $args + ['preview' => true]);
    expect($preview['changes'][0]['after'])->toBe('/replacement')->and($this->seo->redirects)->toBe([]);

    $applied = Operations::applyFix(Actor::for(5), $args);
    expect($applied['changes'][0]['after'])->toBe('/replacement');
});

it('creates an unpublished draft with the previewed parent and title and can undo it', function () {
    adfsUser(5, ['adataforseo', 'new_document', 'save_document', 'delete_document']);
    $parent = adfsDocument('houses', ['template' => 8]);
    $target = adfsMcpTarget($this->project);
    $args = ['target' => $target->id, 'parent' => $parent, 'pagetitle' => 'Homes in Avalon'];

    $preview = Operations::createDraft(Actor::for(5), $args + ['preview' => true]);
    expect(array_column($preview['changes'], 'after', 'field'))->toBe(['pagetitle' => 'Homes in Avalon', 'alias' => 'avalon', 'parent' => (string) $parent])
        ->and(Capsule::table('site_content')->count())->toBe(1)
        ->and(Change::query()->count())->toBe(0);

    $applied = Operations::createDraft(Actor::for(5), $args);
    $doc = $applied['changes'][0]['resource_id'];
    $draft = Capsule::table('site_content')->find($doc);
    expect((int) $draft->published)->toBe(0)
        ->and((int) $draft->parent)->toBe($parent)
        ->and((int) $draft->template)->toBe(8)
        ->and($draft->pagetitle)->toBe('Homes in Avalon')
        ->and($applied['target']['document_id'])->toBe($doc)
        ->and(fn () => Operations::createDraft(Actor::for(5), $args))->toThrow(WorkbenchException::class);

    Operations::undoChange(Actor::for(5), ['change' => $applied['changes'][0]['id']]);
    expect(Capsule::table('site_content')->where('id', $doc)->exists())->toBeFalse()
        ->and($target->refresh()->document_id)->toBeNull();
});

it('requires document creation permissions and access to the chosen parent', function () {
    adfsUser(5, ['adataforseo', 'save_document']);
    adfsUser(6, ['adataforseo', 'save_document', 'new_document']);
    $target = adfsMcpTarget($this->project);
    $parent = adfsDocument('private');
    Capsule::table('document_groups')->insert(['document_group' => 3, 'document' => $parent]);

    expect(fn () => Operations::createDraft(Actor::for(5), ['target' => $target->id]))->toThrow(WorkbenchException::class)
        ->and(fn () => Operations::createDraft(Actor::for(6), ['target' => $target->id, 'parent' => $parent]))->toThrow(WorkbenchException::class)
        ->and(Change::query()->count())->toBe(0)
        ->and(Capsule::table('site_content')->count())->toBe(1)
        ->and(EventLog::$lines)->not->toBeEmpty();
});

it('scopes draft targets and undo changes to the current project', function () {
    adfsAdmin();
    $other = adfsProject(['domain_key' => 'other']);
    $target = adfsMcpTarget($other);
    $change = Change::query()->create(['project_id' => $other->id, 'user_id' => 1, 'target' => 'sseo', 'field' => 'meta_title', 'applied_at' => now()]);

    expect(fn () => Operations::createDraft(Actor::for(1), ['target' => $target->id]))->toThrow(WorkbenchException::class)
        ->and(fn () => Operations::undoChange(Actor::for(1), ['change' => $change->id]))->toThrow(WorkbenchException::class)
        ->and(Change::query()->find($change->id)->undone_at)->toBeNull()
        ->and(Capsule::table('site_content')->count())->toBe(0);
});

it('refuses undo after permissions are revoked or the applied value changes', function () {
    adfsUser(5, ['adataforseo', 'save_document']);
    $doc = adfsDocument('page');
    $issue = adfsMcpIssue($this->project, $doc);
    $applied = Operations::applyFix(Actor::for(5), ['issue' => $issue->id]);
    $args = ['change' => $applied['changes'][0]['id']];
    adfsRevoke(5, 'save_document');

    expect(fn () => Operations::undoChange(Actor::for(5), $args))->toThrow(WorkbenchException::class);

    adfsAdmin(1);
    $this->seo->fields[$doc]['base']['meta_title'] = 'Edited later';
    expect(fn () => Operations::undoChange(Actor::for(1), $args))->toThrow(WorkbenchException::class)
        ->and(Change::query()->find($args['change'])->undone_at)->toBeNull()
        ->and($this->seo->fields[$doc]['base']['meta_title'])->toBe('Edited later');
});

it('estimates with options for readers and starts the same job only for spenders', function () {
    adfsUser(5, ['adataforseo']);
    adfsUser(6, ['adataforseo', 'adataforseo_spend']);
    adfsDemoKey();
    $args = ['kind' => 'audit', 'options' => ['crawl_pages' => 100]];
    $estimate = Operations::estimate(Actor::for(5), $args);
    expect($estimate['estimate']['amount'])->toBe(0.015)
        ->and(Job::query()->count())->toBe(0)
        ->and(fn () => Operations::startJob(Actor::for(5), $args))->toThrow(WorkbenchException::class);

    $started = Operations::startJob(Actor::for(6), $args);
    expect($started['job'])->toMatchArray([
        'kind' => 'audit', 'status' => Job::QUEUED, 'estimate' => 0.015, 'spent' => 0.0,
        'options' => ['crawl_pages' => 100], 'user_id' => 6, 'trigger' => 'mcp',
    ])->and($started['job']['uuid'])->not->toBeEmpty();
});

it('keeps high or unknown job estimates behind approval even with approval arguments', function (bool $unknown) {
    adfsAdmin();
    adfsDemoKey();
    $this->project->forceFill(['approve_above' => 0.001])->save();
    $args = $unknown
        ? ['kind' => 'custom', 'options' => ['endpoint' => 'serp/google/organic/live/advanced', 'tasks' => [['keyword' => 'homes', 'location_code' => 2203, 'language_code' => 'en', 'depth' => 100]]]]
        : ['kind' => 'audit', 'options' => ['crawl_pages' => 100]];
    $args['approved'] = true;
    $args['options']['approved'] = true;
    $args['options']['job_cap'] = 10000;

    $started = Operations::startJob(Actor::for(1), $args);
    $job = Job::query()->where('uuid', $started['job']['uuid'])->firstOrFail();
    expect($started['job']['status'])->toBe(Job::AWAITING_APPROVAL)
        ->and($job->approved)->toBeFalse()
        ->and($job->options)->not->toHaveKeys(['approved', 'job_cap'])
        ->and(Capsule::table('system_cli_tasks')->count())->toBe(0);

    if ($unknown) {
        expect($started['job']['estimate'])->toBeNull();
    }
})->with([false, true]);

it('refuses jobs over the job or monthly cap without leaving a queued job', function (string $cap) {
    adfsAdmin();
    adfsDemoKey();
    $this->project->forceFill([$cap => 0.001])->save();

    expect(fn () => Operations::startJob(Actor::for(1), ['kind' => 'audit', 'options' => ['crawl_pages' => 100]]))->toThrow(WorkbenchException::class)
        ->and(Job::query()->count())->toBe(0)
        ->and(Capsule::table('system_cli_tasks')->count())->toBe(0)
        ->and(Call::query()->sum('cost'))->toBe(0)
        ->and(EventLog::$lines)->not->toBeEmpty();
})->with(['job_cap', 'monthly_cap']);
