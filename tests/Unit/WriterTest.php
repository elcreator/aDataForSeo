<?php

use Elcreator\aDataForSeo\Gateway\Client as Gateway;
use Elcreator\aDataForSeo\Http\Controllers\BootstrapController;
use Elcreator\aDataForSeo\Http\Controllers\WriterController;
use Elcreator\aDataForSeo\Integrations\SSeo;
use Elcreator\aDataForSeo\Models\Call;
use Elcreator\aDataForSeo\Models\Issue;
use Elcreator\aDataForSeo\Models\Keyword;
use Elcreator\aDataForSeo\Models\Target;
use Elcreator\aDataForSeo\Services\Actor;
use Elcreator\aDataForSeo\Services\GatewayWriter;
use Elcreator\aDataForSeo\Services\Projects;
use Elcreator\aDataForSeo\Services\WorkbenchException;
use Elcreator\aDataForSeo\Support\Keys;
use Illuminate\Http\Request;

/**
 * The `gateway` Writer: the page's own data goes to ai.artur.work as facts, the price is
 * asked first, the charge is booked in euros (also for a refused text), and nothing is
 * written to the site: the variants only fill the fix dialog.
 */

beforeEach(function () {
    adfsReset();
    $this->seo = new AdfsFakeSeo();
    SSeo::fake($this->seo);
    $this->gateway = new AdfsFakeGateway();
    Gateway::useTransport($this->gateway);
    adfsUser(5, ['adataforseo', 'adataforseo_spend', 'save_document']);
    Keys::saveGateway('site', null, 'gw-live-key-0123456789');
    $this->project = adfsProject(['settings' => ['site_languages' => ['cs', 'en'], 'writer' => 'gateway']]);
    $this->section = adfsDocument('byty', ['pagetitle' => 'Byty']);
    $this->doc = adfsDocument('pronajem-byty-atlantida', ['pagetitle' => 'Pronájem bytů Atlantida', 'parent' => $this->section, 'introtext' => 'Byty k pronájmu v Atlantidě přímo od majitelů, s fotografiemi a mapou.']);
    adfsDocument('prodej-byty-atlantida', ['pagetitle' => 'Prodej bytů Atlantida', 'parent' => $this->section]);
    adfsDocument('pronajem-byty-avalon', ['pagetitle' => 'Pronájem bytů Avalon', 'parent' => $this->section]);
    adfsDocument('byt-1', ['parent' => $this->doc]);
    adfsDocument('byt-2', ['parent' => $this->doc]);
    adfsDocument('byt-skryty', ['parent' => $this->doc, 'published' => 0]);
    $this->target = Target::query()->create(['project_id' => $this->project->id, 'country' => 'CZ', 'geo' => 'Atlantida', 'deal' => 'rent', 'type' => 'flat', 'lang' => 'cs', 'document_id' => $this->doc, 'url' => 'https://example.test/pronajem-byty-atlantida', 'status' => 'exists', 'score' => 10]);
    $kw = fn (string $k, int $v) => Keyword::query()->create(['project_id' => $this->project->id, 'market' => 'CZ', 'lang' => 'cs', 'keyword' => $k, 'volume' => $v, 'target_id' => $this->target->id]);
    $this->target->forceFill(['primary_keyword_id' => $kw('pronájem bytů atlantida', 880)->id])->save();
    $kw('byty k pronájmu atlantida', 320);
    $kw('podnájem atlantida', 90);
});

function adfsWriterIssue($project, int $doc, array $attributes = []): Issue
{
    return Issue::query()->create($attributes + ['project_id' => $project->id, 'source' => 'audit', 'code' => 'duplicate_title', 'severity' => 'medium', 'document_id' => $doc, 'fix' => 'seo_fields',
        'url' => 'https://example.test/pronajem-byty-atlantida', 'url_hash' => sha1(uniqid()), 'status' => 'open', 'details' => ['suggest' => ['meta_title' => 'Pronájem bytů Atlantida | Example Realty'], 'lang' => 'base']]);
}

it('sends the keyword, the page, its neighbours and only facts the site holds', function () {
    $body = GatewayWriter::body($this->project, $this->target);

    expect($body['lang'])->toBe('cs')
        ->and($body['target'])->toBe(['keyword' => 'pronájem bytů atlantida', 'secondary' => ['byty k pronájmu atlantida', 'podnájem atlantida']])
        ->and($body['page'])->toBe(['title' => 'Pronájem bytů Atlantida', 'h1' => 'Pronájem bytů Atlantida', 'summary' => 'Byty k pronájmu v Atlantidě přímo od majitelů, s fotografiemi a mapou.', 'url' => 'https://example.test/pronajem-byty-atlantida'])
        ->and($body['facts'])->toBe([
            'siteName' => 'Example Realty',
            'place' => ['cs' => 'Atlantida', 'en' => 'Atlantis', 'uk' => 'Атлантида', 'ru' => 'Атлантида'],
            'deal' => 'pronájem',
            'propertyType' => 'bytů',
            'pagesInSection' => 2,
        ])
        ->and($body['siblings'])->toBe(['Prodej bytů Atlantida', 'Pronájem bytů Avalon'])
        ->and($body['variants'])->toBe(3)
        // Demand and difficulty are ours, not the visitor's: they never become facts a title could state.
        ->and(json_encode($body))->not->toContain('880');
});

it('writes for a finding in the language of the page the finding belongs to', function () {
    $issue = adfsWriterIssue($this->project, $this->doc);
    $body = GatewayWriter::body($this->project, $issue);

    expect($body['lang'])->toBe('cs')
        ->and($body['target']['keyword'])->toBe('pronájem bytů atlantida');

    $this->target->delete();
    $body = GatewayWriter::body($this->project, $issue);

    expect($body['lang'])->toBe('cs')
        ->and($body['target'])->toBe(['keyword' => 'Pronájem bytů Atlantida'])
        ->and($body['facts'])->toBe(['siteName' => 'Example Realty', 'pagesInSection' => 2]);
});

it('asks the price for free and books nothing', function () {
    $quote = (new GatewayWriter(Actor::for(5), $this->project))->quote($this->target);

    expect($quote['estimate'])->toMatchArray(['low' => 0.0006, 'high' => 0.0024])
        ->and($quote['currency'])->toBe('EUR')
        ->and($this->gateway->submits())->toBe(0)
        ->and(Call::query()->count())->toBe(0);
});

it('books the charge in euros and writes nothing to the site', function () {
    $answer = (new GatewayWriter(Actor::for(5), $this->project))->meta($this->target, true);

    $call = Call::query()->sole();
    $sent = collect($this->gateway->requests)->last();

    expect($answer['variants'][0])->toMatchArray(['title' => 'Pronájem bytů Atlantida | Example Realty', 'title_chars' => 39, 'claims' => [['text' => '12 nabídek', 'keys' => ['pagesInSection']]]])
        ->and($answer['cost'])->toBe(0.0011)
        ->and($answer['generated'])->toBeTrue()
        ->and($call->endpoint)->toBe('gateway/seo/meta')
        ->and($call->currency)->toBe('EUR')
        ->and($call->cost)->toBe(0.0011)
        ->and($call->user_id)->toBe(5)
        ->and($call->project_id)->toBe($this->project->id)
        ->and($sent['key'])->not->toBeEmpty()
        ->and($sent['body'])->not->toHaveKey('quote')
        ->and($this->seo->writes)->toBe(0);
});

it('books what a refused text cost and tells the manager', function () {
    $this->gateway->refuse = [422, ['type' => 'invalid_request_error', 'message' => 'Unsupported claim.', 'code' => 'seo_unsupported_claim', 'runs' => ['r1', 'r2', 'r3'], 'chargedEur' => 0.0019]];

    try {
        (new GatewayWriter(Actor::for(5), $this->project))->meta($this->target, true);
        $this->fail('a refused text must throw');
    } catch (WorkbenchException $e) {
        expect($e->status)->toBe(422)->and($e->error)->toBe('writer_refused')->and($e->getMessage())->toContain('€0.0019');
    }

    expect(Call::query()->sole()->cost)->toBe(0.0019);
});

it('stops before the request at the monthly cap in euros', function () {
    $this->project->forceFill(['monthly_cap' => 0.001])->save();

    expect(fn () => (new GatewayWriter(Actor::for(5), $this->project))->meta($this->target, true))->toThrow(\Elcreator\aDataForSeo\Api\ApiException::class)
        ->and($this->gateway->submits())->toBe(0)
        ->and(Call::query()->count())->toBe(0);
});

it('asks for confirmation when the price is above the approval threshold', function () {
    $this->project->forceFill(['approve_above' => 0.001])->save();

    $answer = (new GatewayWriter(Actor::for(5), $this->project))->meta($this->target, false);

    expect($answer['needs_confirmation'])->toBeTrue()->and($this->gateway->submits())->toBe(0);
});

it('refuses when switched off, without a key, for other fixes and for someone who may not edit the page', function (callable $arrange, string $error) {
    $issue = adfsWriterIssue($this->project, $this->doc);
    $arrange($this, $issue);

    try {
        (new GatewayWriter(Actor::for(5), $this->project->refresh()))->quote($issue);
        $this->fail('expected a refusal');
    } catch (WorkbenchException $e) {
        expect($e->error)->toBe($error);
    }

    expect($this->gateway->requests)->toBe([]);
})->with([
    'templates chosen' => [function ($t) { $t->project->forceFill(['settings' => ['writer' => 'template']])->save(); }, 'error_writer_off'],
    'no gateway key' => [function () { \Elcreator\aDataForSeo\Models\Key::query()->delete(); }, 'error_writer_no_key'],
    'a redirect fix' => [function ($t, $i) { $i->forceFill(['fix' => 'redirect'])->save(); }, 'error_writer_unsupported'],
    'a robots-only fix' => [function ($t, $i) { $i->forceFill(['details' => ['suggest' => ['robots' => 'index, follow']]])->save(); }, 'error_writer_unsupported'],
    'no save_document' => [function () { adfsRevoke(5, 'save_document'); }, 'forbidden'],
    'no spend permission' => [function () { adfsRevoke(5, 'adataforseo_spend'); }, 'forbidden'],
]);

it('keeps the choice as a project setting and shows the button only when it can be paid', function () {
    adfsUser(7, ['adataforseo', 'adataforseo_spend', 'adataforseo_manage']);
    $projects = new Projects();

    $project = $projects->update($this->project, ['settings' => ['writer' => 'anything']], Actor::for(7));
    expect($project->setting('writer'))->toBe('template');

    AdfsTestCore::$loginUserId = 7;
    $flags = fn () => json_decode((string) (new BootstrapController())->show()->getContent(), true)['flags'];
    expect($flags()['writer_ai'])->toBeFalse();

    $projects->update($project, ['settings' => ['writer' => 'gateway']], Actor::for(7));
    expect($flags()['writer_ai'])->toBeTrue();

    \Elcreator\aDataForSeo\Models\Key::query()->delete();
    expect($flags()['writer_ai'])->toBeFalse();
});

it('answers the page through the controller, by finding or by target', function () {
    AdfsTestCore::$loginUserId = 5;
    $issue = adfsWriterIssue($this->project, $this->doc);
    $controller = new WriterController();

    $quote = json_decode((string) $controller->quote(Request::create('/writer/quote', 'POST', ['issue' => $issue->id]))->getContent(), true);
    $meta = $controller->meta(Request::create('/writer/meta', 'POST', ['target' => $this->target->id, 'confirm' => true]));
    $missing = $controller->quote(Request::create('/writer/quote', 'POST', ['issue' => 99999]));

    expect($quote['ok'])->toBeTrue()->and($quote['estimate']['high'])->toBe(0.0024)
        ->and($meta->getStatusCode())->toBe(200)
        ->and(json_decode((string) $meta->getContent(), true)['variants'])->toHaveCount(1)
        ->and($missing->getStatusCode())->toBe(404);
});
