<?php

use Elcreator\aDataForSeo\Gateway\Client as Gateway;
use Elcreator\aDataForSeo\Integrations\SSeo;
use Elcreator\aDataForSeo\Listeners\DocumentPanel;
use Elcreator\aDataForSeo\Listeners\SeoFieldsHint;
use Elcreator\aDataForSeo\Listeners\SeoFieldsJournal;
use Elcreator\aDataForSeo\Models\Change;
use Elcreator\aDataForSeo\Models\Issue;
use Elcreator\aDataForSeo\Models\Keyword;
use Elcreator\aDataForSeo\Models\Target;
use Elcreator\aDataForSeo\Services\Actor;
use Elcreator\aDataForSeo\Services\Fixes;
use Elcreator\aDataForSeo\Support\Keys;

/**
 * The block under sSeo's own fields (`sSeoFieldsRender`) and the booking of what "Use this"
 * put there once the document is saved: a journal row that can be undone, the finding closed.
 */

beforeEach(function () {
    adfsReset();
    $this->seo = new AdfsFakeSeo();
    SSeo::fake($this->seo);
    adfsUser(5, ['adataforseo', 'adataforseo_spend', 'save_document']);
    AdfsTestCore::$loginUserId = 5;
    $this->project = adfsProject();
    $this->doc = adfsDocument('pronajem-byty-atlantida', ['pagetitle' => 'Pronájem bytů Atlantida']);
    $this->target = Target::query()->create(['project_id' => $this->project->id, 'country' => 'CZ', 'geo' => 'Atlantida', 'deal' => 'rent', 'type' => 'flat', 'lang' => 'cs', 'document_id' => $this->doc, 'status' => 'exists', 'demand_real' => 880, 'tier' => 'P1', 'score' => 5]);
    $this->target->forceFill(['primary_keyword_id' => Keyword::query()->create(['project_id' => $this->project->id, 'market' => 'CZ', 'lang' => 'cs', 'keyword' => 'pronájem bytů atlantida', 'volume' => 880, 'target_id' => $this->target->id])->id])->save();
    $this->issue = Issue::query()->create(['project_id' => $this->project->id, 'source' => 'audit', 'code' => 'duplicate_title', 'severity' => 'medium', 'document_id' => $this->doc, 'fix' => 'seo_fields',
        'url' => 'https://example.test/pronajem-byty-atlantida', 'url_hash' => sha1('x'), 'status' => 'open', 'details' => ['suggest' => ['meta_title' => 'Byty <b>Atlantida</b> | Example Realty'], 'lang' => 'base']]);
    $this->params = ['resource_id' => $this->doc, 'resource_type' => 'document', 'lang' => 'base', 'domain_key' => 'default', 'fields' => []];
});

it('shows the query, its demand and one-click values under sSeo’s fields', function () {
    $html = SeoFieldsHint::handle($this->params);

    expect($html)->toContain('pronájem bytů atlantida')->toContain('880 searches a month')->toContain('P1')
        ->toContain('data-adfs-lang="base"')
        ->toContain('data-field="meta_title" data-value="Byty &lt;b&gt;Atlantida&lt;/b&gt; | Example Realty" data-issue="' . $this->issue->id . '"')
        ->toContain('data-field="meta_title" data-value="Pronájem bytů Atlantida" data-issue="0" data-target="' . $this->target->id . '"')
        ->toContain('data-field="meta_description"')
        ->not->toContain('<b>Atlantida</b>')
        ->not->toContain('data-adfs-ai data-issue');
});

it('stays silent for other resources, for someone without the module and for a page it knows nothing about', function () {
    expect(SeoFieldsHint::handle(['resource_type' => 'product'] + $this->params))->toBe('')
        ->and(SeoFieldsHint::handle(['resource_id' => adfsDocument('kontakty')] + $this->params))->toBe('');

    adfsRevoke(5, 'adataforseo');
    expect(SeoFieldsHint::handle($this->params))->toBe('');
});

it('offers AI variants only when switched on and payable, through the module’s own route', function () {
    expect(SeoFieldsHint::handle($this->params))->not->toContain('data-adfs-ai data-issue');

    $this->project->forceFill(['settings' => ['writer' => 'gateway']])->save();
    Keys::saveGateway('site', null, 'gw-live-key-0123456789');
    $html = SeoFieldsHint::handle($this->params);

    expect($html)->toContain('data-adfs-ai data-issue="0" data-target="' . $this->target->id . '"')
        ->toContain('/modules/' . md5('aDataForSeo') . '"');

    adfsRevoke(5, 'adataforseo_spend');
    expect(SeoFieldsHint::handle($this->params))->not->toContain('data-adfs-ai data-issue');
});

it('steps the old document block aside when sSeo shows ours under its fields', function () {
    expect(DocumentPanel::handle(['id' => $this->doc]))->toContain('<div class="tab-page" id="adataforseo-panel"><h2 class="tab">Search</h2>')->toContain('tpSettings.addTabPage(document.getElementById("adataforseo-panel"))');

    $this->seo->fieldsHook = true;
    expect(DocumentPanel::handle(['id' => $this->doc]))->toBe('');
});

it('books a value chosen with “Use this” when the document is saved, closes the finding and can undo it', function () {
    $this->seo->fields[$this->doc]['base']['meta_title'] = 'Byty Atlantida | Example Realty';
    $post = [
        'sseo' => ['base' => ['meta_title' => 'Byty Atlantida, 12 nabídek | Example Realty', 'meta_description' => 'Unchanged']],
        'adfs_seo' => ['base' => [
            'meta_title' => ['before' => 'Old title', 'issue' => (string) $this->issue->id, 'target' => ''],
            'meta_description' => ['before' => 'Unchanged', 'issue' => '', 'target' => (string) $this->target->id],
        ]],
    ];

    $changes = SeoFieldsJournal::book($this->doc, $post, Actor::for(5));

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->only(['project_id', 'issue_id', 'user_id', 'target', 'resource_id', 'lang', 'field', 'before', 'after']))->toBe([
            'project_id' => $this->project->id, 'issue_id' => $this->issue->id, 'user_id' => 5, 'target' => 'sseo', 'resource_id' => $this->doc,
            'lang' => 'base', 'field' => 'meta_title', 'before' => 'Old title', 'after' => 'Byty Atlantida, 12 nabídek | Example Realty',
        ])
        ->and($this->issue->refresh()->status)->toBe(Issue::APPLIED);

    // sSeo stored the posted value; undo from the module puts the old one back.
    $this->seo->fields[$this->doc]['base']['meta_title'] = 'Byty Atlantida, 12 nabídek | Example Realty';
    adfsUser(6, ['adataforseo', 'save_document']);
    (new Fixes(Actor::for(6)))->undo($this->project, $changes[0]->id);

    expect($this->seo->fields[$this->doc]['base']['meta_title'])->toBe('Old title')
        ->and($this->issue->refresh()->status)->toBe(Issue::OPEN);
});

it('books nothing without a suggestion, for a finding of another page, or without the right to edit the page', function () {
    $other = adfsDocument('kontakty');
    $post = ['sseo' => ['base' => ['meta_title' => 'Typed by hand']], 'adfs_seo' => ['base' => ['meta_title' => ['before' => 'Old', 'issue' => (string) $this->issue->id]]]];

    expect(SeoFieldsJournal::book($this->doc, ['sseo' => $post['sseo']], Actor::for(5)))->toBe([]);

    $foreign = SeoFieldsJournal::book($other, $post, Actor::for(5));
    expect($foreign)->toHaveCount(1)->and($foreign[0]->issue_id)->toBeNull()
        ->and($this->issue->refresh()->status)->toBe(Issue::OPEN);

    adfsRevoke(5, 'save_document');
    expect(SeoFieldsJournal::book($this->doc, $post, Actor::for(5)))->toBe([])
        ->and(Change::query()->count())->toBe(1);
});
