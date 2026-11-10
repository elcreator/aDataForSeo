<?php

use Elcreator\aDataForSeo\Integrations\SSeo;
use Elcreator\aDataForSeo\Models\Change;
use Elcreator\aDataForSeo\Models\Issue;
use Elcreator\aDataForSeo\Models\Target;
use Elcreator\aDataForSeo\Services\Actor;
use Elcreator\aDataForSeo\Services\Fixes;
use Elcreator\aDataForSeo\Services\WorkbenchException;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Hard rules 5 and 8: nothing changes without a click, the permission is the
 * CMS's own and is checked when the fix is applied, every replaced value is
 * kept and can be undone while the field still holds what we wrote.
 */

beforeEach(function () {
    adfsReset();
    $this->seo = new AdfsFakeSeo();
    SSeo::fake($this->seo);
    $this->project = adfsProject();
    $this->doc = adfsDocument('pronajem-byty-atlantida', ['pagetitle' => 'Pronájem bytů Atlantida']);
});

function adfsIssue($project, array $attributes): Issue
{
    return Issue::query()->create($attributes + ['project_id' => $project->id, 'source' => 'audit', 'severity' => 'high', 'url' => 'https://example.test/x', 'url_hash' => sha1(uniqid()), 'status' => 'open']);
}

it('previews and applies sSeo fields with the value that was there before, and undoes them', function () {
    adfsUser(5, ['adataforseo', 'save_document']);
    $this->seo->fields[$this->doc]['base']['meta_title'] = 'Old';
    $issue = adfsIssue($this->project, ['code' => 'duplicate_title', 'document_id' => $this->doc, 'fix' => 'seo_fields', 'details' => ['suggest' => ['meta_title' => 'Pronájem bytů Atlantida | Example Realty'], 'lang' => 'base']]);
    $fixes = new Fixes(Actor::for(5));

    $preview = $fixes->previewIssue($issue);
    expect($preview['changes'][0])->toMatchArray(['field' => 'meta_title', 'before' => 'Old', 'after' => 'Pronájem bytů Atlantida | Example Realty'])
        ->and($this->seo->writes)->toBe(0);

    $changes = $fixes->applyIssue($issue, ['fields' => ['meta_title' => 'Edited by the manager']]);
    expect($this->seo->fields[$this->doc]['base']['meta_title'])->toBe('Edited by the manager')
        ->and($changes[0]->before)->toBe('Old')
        ->and($issue->refresh()->status)->toBe(Issue::APPLIED);

    $fixes->undo($this->project, $changes[0]->id);
    expect($this->seo->fields[$this->doc]['base']['meta_title'])->toBe('Old')
        ->and(Change::query()->first()->undone_at)->not->toBeNull()
        ->and($issue->refresh()->status)->toBe(Issue::OPEN);
});

it('does not undo a field someone changed since', function () {
    adfsUser(5, ['adataforseo', 'save_document']);
    $issue = adfsIssue($this->project, ['code' => 'empty_description', 'document_id' => $this->doc, 'fix' => 'seo_fields', 'details' => ['suggest' => ['meta_description' => 'New description']]]);
    $changes = (new Fixes(Actor::for(5)))->applyIssue($issue);
    $this->seo->fields[$this->doc]['base']['meta_description'] = 'Changed by hand';

    expect(fn () => (new Fixes(Actor::for(5)))->undo($this->project, $changes[0]->id))->toThrow(WorkbenchException::class)
        ->and($this->seo->fields[$this->doc]['base']['meta_description'])->toBe('Changed by hand');
});

it('checks save_document and document groups when the fix is applied, not before', function () {
    adfsUser(5, ['adataforseo', 'save_document']);
    Capsule::table('document_groups')->insert(['document_group' => 3, 'document' => $this->doc]);
    $issue = adfsIssue($this->project, ['code' => 'empty_title', 'document_id' => $this->doc, 'fix' => 'seo_fields', 'details' => ['suggest' => ['meta_title' => 'T']]]);

    expect(fn () => (new Fixes(Actor::for(5)))->applyIssue($issue))->toThrow(WorkbenchException::class);

    adfsUser(6, ['adataforseo', 'save_document'], null, [3]);
    expect((new Fixes(Actor::for(6)))->applyIssue($issue))->toHaveCount(1);

    adfsUser(7, ['adataforseo'], null, [3]);
    $other = adfsIssue($this->project, ['code' => 'empty_title', 'document_id' => $this->doc, 'fix' => 'seo_fields', 'details' => ['suggest' => ['meta_title' => 'U']]]);
    expect(fn () => (new Fixes(Actor::for(7)))->applyIssue($other))->toThrow(WorkbenchException::class);
});

it('writes nothing without sSeo and says so', function () {
    SSeo::fake(null);
    adfsUser(5, ['adataforseo', 'save_document']);
    $issue = adfsIssue($this->project, ['code' => 'empty_title', 'document_id' => $this->doc, 'fix' => 'seo_fields', 'details' => ['suggest' => ['meta_title' => 'T']]]);

    expect((new Fixes(Actor::for(5)))->previewIssue($issue)['available'])->toBeFalse()
        ->and(fn () => (new Fixes(Actor::for(5)))->applyIssue($issue))->toThrow(WorkbenchException::class);
});

it('adds a 301 for a broken page through sSeo, once, and removes it on undo', function () {
    adfsAdmin(1);
    $issue = adfsIssue($this->project, ['code' => 'broken_page', 'url' => 'https://example.test/old-offers/flat-123', 'fix' => 'redirect', 'details' => ['suggest' => ['new_url' => 'https://example.test/pronajem-byty-atlantida']]]);
    $fixes = new Fixes(Actor::for(1));

    $changes = $fixes->applyIssue($issue);
    expect($this->seo->redirects[0])->toBe(['old' => 'old-offers/flat-123', 'new' => 'https://example.test/pronajem-byty-atlantida', 'type' => 301, 'site' => 'all']);

    $again = adfsIssue($this->project, ['code' => 'broken_page', 'url' => 'https://example.test/old-offers/flat-123', 'fix' => 'redirect', 'details' => ['suggest' => ['new_url' => '/']]]);
    expect(fn () => $fixes->applyIssue($again))->toThrow(WorkbenchException::class);

    $fixes->undo($this->project, $changes[0]->id);
    expect($this->seo->redirects)->toBe([]);
});

it('refuses a redirect without the sSeo module permission, or to itself', function () {
    adfsUser(5, ['adataforseo']);
    adfsAdmin(1);
    $issue = adfsIssue($this->project, ['code' => 'broken_page', 'url' => 'https://example.test/gone', 'fix' => 'redirect', 'details' => ['suggest' => ['new_url' => '/']]]);

    expect(fn () => (new Fixes(Actor::for(5)))->applyIssue($issue))->toThrow(WorkbenchException::class)
        ->and(fn () => (new Fixes(Actor::for(1)))->applyIssue($issue, ['new_url' => '/gone']))->toThrow(WorkbenchException::class);
});

it('creates an unpublished draft for a missing page and deletes it on undo while untouched', function () {
    adfsUser(5, ['adataforseo', 'new_document', 'save_document', 'delete_document']);
    $target = Target::query()->create(['project_id' => $this->project->id, 'country' => 'CZ', 'geo' => 'Avalon', 'deal' => 'sale', 'type' => 'house', 'lang' => 'en',
        'proposed_url' => 'https://example.test/en/houses-for-sale/avalon', 'status' => 'missing', 'demand_real' => 100]);
    $fixes = new Fixes(Actor::for(5));

    $changes = $fixes->applyTarget($target, 'draft', ['parent' => 0]);
    $draft = Capsule::table('site_content')->where('id', $changes[0]->resource_id)->first();

    expect($draft->published)->toBe(0)
        ->and($draft->alias)->toBe('avalon')
        ->and($draft->pagetitle)->toBe('Houses for sale in Avalon')
        ->and($target->refresh()->document_id)->toBe((int) $draft->id)
        ->and($this->seo->fields[$draft->id]['base']['meta_title'])->toBe('Houses for sale in Avalon');

    $fixes->undo($this->project, $changes[0]->id);
    expect(Capsule::table('site_content')->where('id', $draft->id)->exists())->toBeFalse()
        ->and($target->refresh()->document_id)->toBeNull();
});

it('keeps an edited draft on undo', function () {
    adfsUser(5, ['adataforseo', 'new_document', 'save_document', 'delete_document']);
    $target = Target::query()->create(['project_id' => $this->project->id, 'country' => 'CZ', 'geo' => 'Avalon', 'deal' => 'sale', 'type' => 'house', 'lang' => 'en', 'proposed_url' => 'https://example.test/en/houses-for-sale/avalon', 'status' => 'missing']);
    $changes = (new Fixes(Actor::for(5)))->applyTarget($target, 'draft');
    Capsule::table('site_content')->where('id', $changes[0]->resource_id)->update(['editedon' => time() + 100]);

    expect(fn () => (new Fixes(Actor::for(5)))->undo($this->project, $changes[0]->id))->toThrow(WorkbenchException::class)
        ->and(Capsule::table('site_content')->where('id', $changes[0]->resource_id)->exists())->toBeTrue();
});

it('refuses a draft without new_document', function () {
    adfsUser(5, ['adataforseo', 'save_document']);
    $target = Target::query()->create(['project_id' => $this->project->id, 'country' => 'CZ', 'geo' => 'Avalon', 'deal' => 'sale', 'type' => 'house', 'lang' => 'en', 'status' => 'missing']);

    expect(fn () => (new Fixes(Actor::for(5)))->applyTarget($target, 'draft'))->toThrow(WorkbenchException::class)
        ->and(Capsule::table('site_content')->count())->toBe(1);
});

it('marks a manual finding done and gives instructions', function () {
    adfsUser(5, ['adataforseo']);
    $issue = adfsIssue($this->project, ['code' => 'crawl_blocked', 'fix' => 'manual']);

    $preview = (new Fixes(Actor::for(5)))->previewIssue($issue);
    (new Fixes(Actor::for(5)))->applyIssue($issue);

    expect($preview['instructions'])->toContain('bot protection')
        ->and($issue->refresh()->status)->toBe(Issue::RESOLVED);
});

it('sees only this project’s findings', function () {
    adfsUser(5, ['adataforseo']);
    $other = adfsProject(['domain_key' => 'second']);
    $issue = adfsIssue($other, ['code' => 'crawl_blocked', 'fix' => 'manual']);

    expect(fn () => (new Fixes(Actor::for(5)))->issue($this->project, $issue->id))->toThrow(WorkbenchException::class);
});
