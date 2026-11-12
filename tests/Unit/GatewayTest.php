<?php

use Carbon\Carbon;
use Elcreator\aDataForSeo\Api\ApiException;
use Elcreator\aDataForSeo\Api\Context;
use Elcreator\aDataForSeo\Api\Transport;
use Elcreator\aDataForSeo\Engine\Plans\Plan;
use Elcreator\aDataForSeo\Engine\Runtime;
use Elcreator\aDataForSeo\Engine\Slice;
use Elcreator\aDataForSeo\Engine\SliceResult;
use Elcreator\aDataForSeo\Engine\Steps\StepRunner;
use Elcreator\aDataForSeo\Gateway\Client as Gateway;
use Elcreator\aDataForSeo\Gateway\Connect;
use Elcreator\aDataForSeo\Gateway\ConnectRelay;
use Elcreator\aDataForSeo\Gateway\Credentials as GatewayKey;
use Elcreator\aDataForSeo\Http\Controllers\BootstrapController;
use Elcreator\aDataForSeo\Http\Controllers\ExplorerController;
use Elcreator\aDataForSeo\Http\Controllers\GatewayController;
use Elcreator\aDataForSeo\Mcp\Operations;
use Elcreator\aDataForSeo\Models\Call;
use Elcreator\aDataForSeo\Models\Issue;
use Elcreator\aDataForSeo\Models\Job;
use Elcreator\aDataForSeo\Models\Key;
use Elcreator\aDataForSeo\Models\Keyword;
use Elcreator\aDataForSeo\Models\Step;
use Elcreator\aDataForSeo\Models\Target;
use Elcreator\aDataForSeo\Services\Actor;
use Elcreator\aDataForSeo\Services\Jobs;
use Elcreator\aDataForSeo\Services\Projects;
use Elcreator\aDataForSeo\Services\WorkbenchException;
use Elcreator\aDataForSeo\Support\EventLog;
use Elcreator\aDataForSeo\Support\Keys;
use Elcreator\aDataForSeo\Support\Manager;
use Elcreator\aDataForSeo\Support\Principal;
use EvolutionCMS\Models\SystemCliTask;
use Illuminate\Http\Request;

/**
 * Paying from the ai.artur.work balance: the gateway client and its error mapping, quotes
 * feeding the approval gate and the budgets in euros, whole jobs end to end against a
 * scripted gateway, what the gateway cannot do, and the key.
 */

beforeEach(function () {
    adfsReset();
    config(['cms.settings.aDataForSeo.queue.driver' => 'system']);
});

/** A scripted gateway, installed for every client built from now on. */
function adfsGateway(array $results = []): AdfsFakeGateway
{
    $gateway = new AdfsFakeGateway($results);
    Gateway::useTransport($gateway);

    return $gateway;
}

function adfsGatewayJson($response): array
{
    return json_decode((string) $response->getContent(), true);
}

const ADFS_AUDIT = [
    'site' => 'example.test', 'pagesCrawled' => 30, 'blocked' => false,
    'issues' => [
        ['code' => 'title_missing', 'severity' => 'high', 'url' => 'https://example.test/pronajem-byty-atlantida', 'details' => []],
        ['code' => 'broken_page', 'severity' => 'high', 'url' => 'https://example.test/stara-stranka', 'details' => ['status' => 404]],
        ['code' => 'title_duplicate', 'severity' => 'medium', 'url' => 'https://example.test/kontakty', 'details' => ['pages' => 2]],
        ['code' => 'canonical_missing', 'severity' => 'low', 'url' => 'https://example.test/kontakty', 'details' => []],
        ['code' => 'hreflang_no_self', 'severity' => 'low', 'url' => 'https://example.test/kontakty', 'details' => []],
        ['code' => 'broken_link', 'severity' => 'medium', 'url' => 'https://example.test/kontakty', 'details' => ['to' => 'https://example.test/nikde', 'status' => 404]],
    ],
];

describe('gateway client', function () {
    it('maps the gateway’s error envelope into the plugin’s vocabulary', function (int $status, array $error, string $kind, bool $retryable, ?string $refusal) {
        $e = Gateway::failure($status, ['type' => 'error', 'error' => $error]);

        expect($e)->toBeInstanceOf(ApiException::class)
            ->and($e->error)->toBe($kind)
            ->and($e->retryable)->toBe($retryable)
            ->and($e->refusal)->toBe($refusal)
            ->and($e->getMessage())->not->toStartWith('aDataForSeo::');
    })->with([
        'a wrong key' => [401, adfsGatewayError('authentication_error', 'Bad key.'), 'auth', false, null],
        'a key from another account' => [403, adfsGatewayError('permission_error', 'No.'), 'auth', false, null],
        'balance out' => [402, adfsGatewayError('insufficient_quota', 'No money.', 'insufficient_quota'), 'balance', false, 'balance'],
        'the key’s own cap' => [402, adfsGatewayError('insufficient_quota', 'Cap.', 'key_spend_cap_reached'), 'key_cap', false, 'key_cap'],
        'a market the data does not cover' => [400, adfsGatewayError('invalid_request_error', 'Covered by: serp.', 'seo_location_unsupported'), 'location', false, null],
        'a malformed request' => [400, adfsGatewayError('invalid_request_error', 'domain must be a domain.'), 'invalid', false, null],
        'a task the key does not know' => [404, adfsGatewayError('not_found_error', 'No task.', 'task_not_found'), 'task_not_found', false, null],
        'a provider that is down' => [503, adfsGatewayError('api_error', 'Down.', 'seo_unavailable'), 'unavailable', true, null],
        'the same request still running' => [409, adfsGatewayError('invalid_request_error', 'Busy.', 'idempotency_in_progress'), 'busy', true, null],
        'rate limited' => [429, adfsGatewayError('rate_limit_error', 'Slow down.'), 'http', true, null],
        'a gateway fault' => [500, adfsGatewayError('api_error', 'Oops.'), 'http', true, null],
    ]);

    it('names the right balance in a refusal and tells the location problem to the manager', function () {
        $balance = Gateway::failure(402, ['error' => ['code' => 'insufficient_quota']]);
        $location = Gateway::failure(400, ['error' => ['code' => 'seo_location_unsupported', 'message' => 'Covered by: labs (cs).']]);

        expect($balance->getMessage())->toContain('ai.artur.work')->and($balance->getMessage())->not->toContain('DataForSEO balance')
            ->and($location->getMessage())->toContain('Covered by: labs (cs).');
    });

    it('sends the key as a bearer token to the configured gateway only', function () {
        config(['cms.settings.aDataForSeo.gateway.url' => 'https://gw.example.test/']);
        $gateway = adfsGateway();
        $client = new Gateway(new GatewayKey('gw-key-aaaaaaaa'));

        $client->account();
        $client->prices();

        expect($gateway->requests)->toHaveCount(2)
            ->and($gateway->requests[0]['auth'])->toBe('Bearer gw-key-aaaaaaaa')
            ->and($gateway->requests[1]['path'])->toBe('seo/prices');

        // The address is configuration, never a literal in the client.
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Gateway/Client.php') . (string) file_get_contents(dirname(__DIR__, 2) . '/src/Gateway/Connect.php');
        expect($source)->not->toContain('ai.artur.work/');
    });

    it('books what the gateway charged once, in euros, whatever number of times it is read', function () {
        $project = adfsGatewayProject();
        $ctx = new Context((int) $project->id, null, null, 7, null, null, false, 1, null, 'EUR');
        $client = new Gateway(new GatewayKey('k'), adfsGateway());

        $client->record($ctx, 'audit', 0.07, 'gw-1');
        $client->record($ctx, 'audit', 0.07, 'gw-1');

        expect(Call::query()->count())->toBe(1)
            ->and(Call::query()->first()->currency)->toBe('EUR')
            ->and(Call::query()->first()->env)->toBe('gateway')
            ->and(Call::query()->first()->cost)->toBe(0.07);
    });
});

describe('stored balance', function () {
    it('lowers the balance shown beside the key by what a task cost, once', function () {
        $project = adfsGatewayProject();
        $row = Key::query()->first();
        $row->forceFill(['balance' => 10.0])->save();
        $ctx = new Context((int) $project->id, null, null, 7, null, null, false, 1, null, 'EUR');
        $client = new Gateway(new GatewayKey('k', 'site', (int) $row->getKey()), adfsGateway());

        $client->record($ctx, 'audit', 0.25, 'gw-bal-1');
        $client->record($ctx, 'audit', 0.25, 'gw-bal-1');

        expect($row->refresh()->balance)->toBe(9.75);
    });
});

describe('quotes, approval and budgets', function () {
    it('turns the gateway’s quote into an estimate: exact, a range, or unknown but never zero', function () {
        $gateway = adfsGateway();
        $estimator = (new Gateway(new GatewayKey('k')))->estimator();
        $gateway->quotes = ['audit' => [0.2, 0.2], 'index-coverage' => [0.01, 0.04], 'keywords/difficulty' => [null, null]];

        $exact = $estimator->quote('audit', ['site' => 'example.test']);
        $range = $estimator->quote('index-coverage', ['domain' => 'example.test']);
        $unknown = $estimator->quote('keywords/difficulty', ['keywords' => ['a']]);

        expect($exact->amount)->toBe(0.2)->and($exact->exact)->toBeTrue()
            ->and($range->low)->toBe(0.01)->and($range->ceiling())->toBe(0.04)
            ->and($unknown->isUnknown())->toBeTrue()->and($unknown->ceiling())->toBeNull();
    });

    it('holds a job whose price the gateway cannot quote for approval, and lets it through once approved', function () {
        $project = adfsGatewayProject();
        $gateway = adfsGateway(['audit' => ADFS_AUDIT]);
        $gateway->quotes['audit'] = [null, null];

        $job = (new Jobs())->start('audit', $project, ['crawl_pages' => 20], Actor::for(7));

        expect($job->status)->toBe(Job::AWAITING_APPROVAL)->and($job->estimate)->toBeNull()->and($job->currency)->toBe('EUR');

        // Not approved: even a queued job stops at the first call and asks again.
        $job->forceFill(['status' => Job::QUEUED])->save();
        $result = (new Slice())->run($job, 30);

        expect($result->status)->toBe(SliceResult::IDLE)->and($job->refresh()->status)->toBe(Job::AWAITING_APPROVAL)
            ->and($gateway->submits())->toBe(0);

        $job = adfsDrive((new Jobs())->approve($job, Actor::for(7)));

        expect($job->status)->toBe(Job::DONE, (string) $job->error)->and($gateway->submits('audit'))->toBe(1);
    });

    it('refuses over the job cap and the monthly cap in euros, and only counts euros against them', function () {
        $project = adfsGatewayProject(['job_cap' => 0.05, 'monthly_cap' => 1.0, 'approve_above' => 5.0]);
        $gateway = adfsGateway();
        $gateway->quotes['audit'] = [0.1, 0.5];

        try {
            (new Jobs())->start('audit', $project, [], Actor::for(7));
            $refused = null;
        } catch (WorkbenchException $e) {
            $refused = $e;
        }

        expect($refused)->not->toBeNull()->and($refused->error)->toBe('refusal_job_cap')->and($refused->getMessage())->toContain('€0.50');

        // A hundred dollars spent on a DataForSEO account says nothing about the euro budget.
        $project->forceFill(['job_cap' => 2.0])->save();
        Call::query()->create(['project_id' => $project->id, 'endpoint' => 'serp/x', 'cost' => 100, 'currency' => 'USD', 'fetched_at' => Carbon::now()]);
        $gateway->quotes['audit'] = [0.1, 0.2];

        expect((new Jobs())->start('audit', $project, [], Actor::for(7))->status)->toBe(Job::QUEUED);

        // Euros do count.
        Call::query()->create(['project_id' => $project->id, 'endpoint' => 'gateway/seo/audit', 'cost' => 0.95, 'currency' => 'EUR', 'env' => 'gateway', 'fetched_at' => Carbon::now()]);
        Job::query()->delete();

        expect(fn () => (new Jobs())->start('audit', $project, [], Actor::for(7)))->toThrow(WorkbenchException::class);
        expect(implode("\n", EventLog::$lines))->toContain('refusal_monthly_cap');
    });
});

describe('a gateway job, end to end', function () {
    it('runs an audit: one submit, a wait that backs off without a successor, the issues written, the charge booked', function () {
        $project = adfsGatewayProject();
        adfsDocument('pronajem-byty-atlantida');
        adfsDocument('kontakty');
        $gateway = adfsGateway(['audit' => ADFS_AUDIT]);
        $gateway->runningPolls = 1;
        $gateway->charged['audit'] = 0.08;

        $job = (new Jobs())->start('audit', $project, ['crawl_pages' => 50], Actor::for(7));
        $tasksBefore = SystemCliTask::query()->count();
        $result = (new Slice())->run($job, 30);

        expect($result->status)->toBe(SliceResult::WAITING)
            ->and($job->refresh()->status)->toBe(Job::WAITING)
            ->and($job->wake_at->isFuture())->toBeTrue()
            ->and(SystemCliTask::query()->count())->toBe($tasksBefore)
            ->and($gateway->submits('audit'))->toBe(1);

        // Before wake_at nothing is asked again (never sleeps, never polls hot).
        $polls = count(array_filter($gateway->requests, static fn ($r) => str_starts_with($r['path'], 'seo/tasks/')));
        (new Slice())->run($job, 30);
        expect(count(array_filter($gateway->requests, static fn ($r) => str_starts_with($r['path'], 'seo/tasks/'))))->toBe($polls);

        $job = adfsDrive($job);
        $submit = Step::query()->where('job_id', $job->id)->where('kind', 'gw_submit')->first();

        expect($job->status)->toBe(Job::DONE, (string) $job->error)
            ->and($gateway->submits('audit'))->toBe(1)
            ->and($gateway->tasks['gw-1']['body'])->toBe(['site' => 'example.test', 'maxPages' => 50])
            ->and(collect($gateway->requests)->first(static fn ($r) => $r['method'] === 'POST' && empty($r['body']['quote']))['key'])->toBe($job->uuid . ':' . $submit->id)
            ->and(Call::query()->where('env', 'gateway')->count())->toBe(1)
            ->and(Call::query()->where('env', 'gateway')->first()->currency)->toBe('EUR')
            ->and($job->spent)->toBe(0.08)
            ->and($job->result['audit']['pages_crawled'])->toBe(30);

        $title = Issue::query()->where('code', 'empty_title')->first();
        $broken = Issue::query()->where('code', 'broken_page')->first();
        $other = Issue::query()->where('code', 'audit_other')->first();
        $link = Issue::query()->where('code', 'broken_link')->first();

        expect($title->document_id)->not->toBeNull()->and($title->fix)->toBe('seo_fields')->and($title->severity)->toBe('high')
            ->and($broken->fix)->toBe('redirect')->and($broken->details['suggest']['new_url'])->toBe('https://example.test/')
            ->and(Issue::query()->where('code', 'duplicate_title')->count())->toBe(1)
            ->and($other->fix)->toBe('manual')->and($other->details['gateway_codes'])->toBe(['canonical_missing', 'hreflang_no_self'])
            ->and($link->url)->toBe('https://example.test/kontakty → https://example.test/nikde');
    });

    it('never pays twice: a step run again, or after a crash, repeats the call under the same key', function () {
        $project = adfsGatewayProject();
        $gateway = adfsGateway(['audit' => ADFS_AUDIT]);
        $job = adfsDrive((new Jobs())->start('audit', $project, [], Actor::for(7)));
        $step = Step::query()->where('job_id', $job->id)->where('kind', 'gw_submit')->first();
        $rt = new Runtime($job, $project, null, new Context((int) $project->id, (int) $job->id, null, 7, null, null, true, 1, null, 'EUR'), 30, null, new Gateway(Keys::gatewayFor(Principal::forUser(7))));

        // Run again: the recorded task id ends it with no request at all.
        $before = count($gateway->requests);
        StepRunner::for('gw_submit')->run($step, $rt);
        expect(count($gateway->requests))->toBe($before);

        // A worker that died before writing the id down: the same key brings back the same task.
        $step->forceFill(['dfs_task_ids' => []])->save();
        StepRunner::for('gw_submit')->run($step->refresh(), $rt);

        expect($gateway->tasks)->toHaveCount(1)->and(array_keys((array) $step->refresh()->dfs_task_ids))->toBe(['gw-1']);
    });

    it('does not let a refusal stick: a balance refusal is stored under its key, so the retry uses a new one', function () {
        $project = adfsGatewayProject();
        $gateway = adfsGateway(['audit' => ADFS_AUDIT]);
        $gateway->refuse = [402, adfsGatewayError('insufficient_quota', 'Top up.', 'insufficient_quota')];

        $job = adfsDrive((new Jobs())->start('audit', $project, [], Actor::for(7)));

        expect($job->status)->toBe(Job::FAILED)->and($job->error)->toContain('balance')
            ->and(Call::query()->count())->toBe(0);

        $job = adfsDrive((new Jobs())->retry($job, Actor::for(7)));
        $keys = array_values(array_map(static fn ($r) => $r['key'], array_filter($gateway->requests, static fn ($r) => $r['method'] === 'POST' && empty($r['body']['quote']))));

        expect($job->status)->toBe(Job::DONE, (string) $job->error)->and($keys)->toHaveCount(2)->and($keys[1])->toBe($keys[0] . ':1')
            ->and($gateway->tasks)->toHaveCount(1);
    });

    it('fails a job whose task failed, and books nothing', function () {
        $project = adfsGatewayProject();
        $gateway = adfsGateway(['audit' => ADFS_AUDIT]);
        $gateway->failed = true;

        $job = adfsDrive((new Jobs())->start('audit', $project, [], Actor::for(7)));

        expect($job->status)->toBe(Job::FAILED)->and($job->error)->toContain('nothing was charged')->and(Call::query()->count())->toBe(0);
    });

    it('finishes against the gateway even when the project was switched back while it ran', function () {
        $project = adfsGatewayProject();
        $gateway = adfsGateway(['audit' => ADFS_AUDIT]);
        $job = (new Jobs())->start('audit', $project, [], Actor::for(7));
        $project->forceFill(['billing' => 'own'])->save();

        $job = adfsDrive($job);

        expect($job->status)->toBe(Job::DONE, (string) $job->error)->and($gateway->submits('audit'))->toBe(1);
    });

    it('maps index coverage onto the same findings: pages Google does not show, search pages it shows instead', function () {
        $project = adfsGatewayProject();

        foreach (['a-page', 'b-page', 'c-page', 'd-page'] as $alias) {
            adfsDocument($alias);
        }

        $gateway = adfsGateway(['index-coverage' => [
            'indexed' => 2, 'urls' => ['https://example.test/a-page', 'https://example.test/search?q=x', 'https://elsewhere.test/a-page'],
            'sitemap' => null, 'extraIndexed' => [], 'domain' => 'example.test', 'pagesChecked' => 1, 'note' => 'Results continue past page 1.',
        ]]);

        $job = adfsDrive((new Jobs())->start('coverage', $project, [], Actor::for(7)));
        $coverage = $job->result['coverage'];

        expect($job->status)->toBe(Job::DONE, (string) $job->error)
            ->and($gateway->tasks['gw-1']['body'])->toBe(['domain' => 'example.test', 'maxPages' => 3])
            ->and($coverage['sitemap'])->toBe(4)->and($coverage['indexed'])->toBe(2)->and($coverage['not_indexed'])->toBe(3)
            ->and($coverage['source'])->toBe('gateway')->and($coverage['note'])->toContain('Results continue')
            ->and(Issue::query()->where('code', 'not_indexed')->count())->toBe(3)
            ->and(Issue::query()->where('code', 'not_indexed')->whereNotNull('document_id')->count())->toBe(3)
            ->and(Issue::query()->where('code', 'indexed_not_in_sitemap')->first()->url)->toBe('https://example.test/search?q=x');
    });

    it('scores difficulty in chunks of 100, fills the targets with the counts and keeps unmatched searches in the result', function () {
        $project = adfsGatewayProject();
        $keywords = array_map(static fn ($n) => 'byty atlantida ' . $n, range(1, 130));

        foreach ([1, 2] as $n) {
            $keyword = Keyword::query()->create(['project_id' => $project->id, 'market' => 'CZ', 'lang' => 'cs', 'keyword' => 'byty atlantida ' . $n]);
            Target::query()->create(['project_id' => $project->id, 'country' => 'CZ', 'lang' => 'cs', 'geo' => 'g' . $n, 'primary_keyword_id' => $keyword->id, 'demand_real' => 10]);
        }

        $aggregates = ['results' => 10, 'sites' => 6, 'ugc' => 2, 'unknown' => 2, 'strongSites' => 2, 'strongest' => 71.5, 'median' => 38.5, 'otherLanguage' => 4];
        $gateway = adfsGateway(['keywords/difficulty' => static fn (array $body) => ['items' => array_map(static fn ($k) => $k === 'byty atlantida 2'
            ? ['keyword' => $k, 'difficulty' => null, 'nativeShare' => null, 'serp' => null]
            : ['keyword' => $k, 'difficulty' => 42.5, 'nativeShare' => 0.6, 'serp' => $aggregates], $body['keywords'])]]);

        $job = adfsDrive((new Jobs())->start('difficulty', $project, ['keywords' => $keywords], Actor::for(7)));
        $sizes = array_map(static fn ($t) => count($t['body']['keywords']), array_values($gateway->tasks));
        $first = Target::query()->where('geo', 'g1')->first();
        $second = Target::query()->where('geo', 'g2')->first();

        expect($job->status)->toBe(Job::DONE, (string) $job->error)
            ->and($sizes)->toBe([100, 30])
            ->and($gateway->tasks['gw-1']['body']['location'])->toBe('CZ')->and($gateway->tasks['gw-1']['body']['language'])->toBe('cs')
            ->and($first->kd_serp)->toBe(42.5)->and($first->native_share)->toBe(0.6)
            ->and($first->serp_snapshot['aggregates'])->toBe($aggregates)->and($first->serp_snapshot['results'])->toBe([])->and($first->serp_snapshot['source'])->toBe('gateway')
            // Not measurable stays unknown, never 0.
            ->and($second->kd_serp)->toBeNull()->and($second->serp_snapshot['aggregates'])->toBeNull()
            ->and($job->result['difficulty']['checked'])->toBe(2)
            ->and(count($job->result['difficulty']['items']))->toBe(128);

        // The page reads a snapshot without hosts without breaking.
        expect(\Elcreator\aDataForSeo\Services\Reports::presentTarget($first)['serp']['results'])->toBe([]);
    });

    it('has nothing to score without targets or searches, and says what to do', function () {
        $project = adfsGatewayProject();
        adfsGateway();

        expect(fn () => (new Jobs())->start('difficulty', $project, [], Actor::for(7)))->toThrow(WorkbenchException::class, 'Enter some');
    });
});

describe('what the gateway cannot do', function () {
    it('refuses keyword research, tracking, competitors and raw requests before anything is queued, and logs it', function (string $kind) {
        $project = adfsGatewayProject();
        $gateway = adfsGateway();
        $tasks = SystemCliTask::query()->count();

        foreach ([fn () => (new Jobs())->start($kind, $project, [], Actor::for(7)), fn () => (new Jobs())->estimate($kind, $project, [], Actor::for(7)), fn () => Operations::startJob(Actor::for(7), ['kind' => $kind])] as $attempt) {
            EventLog::$lines = [];

            try {
                $attempt();
                $refused = null;
            } catch (WorkbenchException $e) {
                $refused = $e;
            }

            expect($refused)->not->toBeNull()->and($refused->error)->toBe('error_gateway_unsupported')->and($refused->status)->toBe(409)
                ->and($refused->getMessage())->toContain('own DataForSEO account')
                ->and(implode("\n", EventLog::$lines))->toContain('error_gateway_unsupported')->toContain($kind);
        }

        expect(Job::query()->count())->toBe(0)->and(SystemCliTask::query()->count())->toBe($tasks)->and($gateway->requests)->toBe([])
            ->and(fn () => Plan::for($kind, true))->toThrow(WorkbenchException::class);
    })->with(['research', 'track', 'competitors', 'custom']);

    it('refuses the API explorer, and a schedule for such work logs once', function () {
        $project = adfsGatewayProject(['mode' => 'pro']);
        AdfsTestCore::$loginUserId = 7;

        $response = (new ExplorerController())->estimate(Request::create('/explorer/estimate', 'POST', ['endpoint' => 'serp/google/organic/live/regular', 'payload' => '[{"keyword":"a"}]']));

        expect($response->getStatusCode())->toBe(409)->and(adfsGatewayJson($response)['error'])->toBe('error_gateway_unsupported');

        $project->forceFill(['schedule' => ['research' => 7, 'audit' => 0], 'authorized_by' => 7])->save();
        adfsGateway();
        EventLog::$lines = [];

        expect((new Jobs())->schedules())->toBe(0)->and(implode("\n", EventLog::$lines))->toContain('Scheduled research not started');
    });

    it('still runs the three analyses for a project that is not on the gateway as before', function () {
        adfsAdmin(1);
        adfsDemoKey();
        $project = adfsProject();

        $jobs = new Jobs();
        $job = $jobs->start('coverage', $project, [], Actor::for(1));

        for ($i = 0; $i < 5 && $job->status !== Job::DONE; $i++) {
            $job = $job->status === Job::AWAITING_APPROVAL ? $jobs->approve($job, Actor::for(1)) : $job;
            $job = adfsDrive($job);
        }

        expect($job->status)->toBe(Job::DONE, (string) $job->error)->and($job->currency)->toBe('USD')
            ->and(Step::query()->where('job_id', $job->id)->where('kind', 'gw_submit')->count())->toBe(0);
    });
});

describe('the key and the way to pay', function () {
    it('stores the key encrypted, shows only a mask and never puts it in a payload, a log or a response', function () {
        $project = adfsGatewayProject();
        AdfsTestCore::$loginUserId = 7;
        $gateway = adfsGateway(['audit' => ADFS_AUDIT]);
        $secret = 'gw-live-key-0123456789';

        $job = adfsDrive((new Jobs())->start('audit', $project, [], Actor::for(7)));
        $description = Keys::describe(Principal::forUser(7), $project);
        $everything = json_encode([
            Key::query()->get()->toArray(), Job::query()->get()->toArray(), Step::query()->get()->toArray(), Call::query()->get()->toArray(),
            Issue::query()->get()->toArray(), EventLog::$lines, $description, adfsGatewayJson((new BootstrapController())->show()),
        ]);

        expect($job->status)->toBe(Job::DONE)
            ->and($description['login'])->toBe('gw-l••••••••6789')->and($description['env'])->toBe('gateway')->and($description['currency'])->toBe('EUR')
            ->and($everything)->not->toContain($secret)
            ->and(Key::query()->first()->password_enc)->not->toContain($secret)
            ->and(Keys::gatewayFor(Principal::forUser(7))->apiKey())->toBe($secret)
            ->and(print_r(Keys::gatewayFor(Principal::forUser(7)), true))->not->toContain($secret);

        // The two kinds of key never answer each other’s lookups.
        expect(Keys::for(Principal::forUser(7)))->toBeNull()->and(Keys::siteKey())->toBeNull();
    });

    it('saves a pasted key after checking it, switches the project to pay from it, and keeps the old way on a bad key', function () {
        adfsUser(5, ['adataforseo', 'adataforseo_spend', 'adataforseo_manage']);
        AdfsTestCore::$loginUserId = 5;
        $project = adfsProject();
        $gateway = adfsGateway();

        $response = (new GatewayController())->key(Request::create('/gateway/key', 'POST', ['scope' => 'site', 'key' => 'gw-pasted-key-00000001']));
        $body = adfsGatewayJson($response);

        expect($response->getStatusCode())->toBe(200)->and($body['billing'])->toBe('gateway')->and($body['key']['balance'])->toBe(12.5)
            ->and($body['key']['top_up_url'])->toBe('https://gateway.example.test/profile#balance-card')
            ->and($project->refresh()->billing)->toBe('gateway')
            ->and(json_encode($body))->not->toContain('gw-pasted-key-00000001')
            ->and(array_column($gateway->requests, 'path'))->toBe(['account', 'seo/prices']);

        // A key the gateway refuses replaces nothing.
        Gateway::useTransport(new class implements Transport {
            public function send(string $method, string $url, ?string $body, array $headers, int $timeout, int $connectTimeout): array
            {
                return [401, json_encode(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'Bad key.']])];
            }
        });
        $bad = (new GatewayController())->key(Request::create('/gateway/key', 'POST', ['scope' => 'site', 'key' => 'gw-wrong-key-00000002']));

        expect($bad->getStatusCode())->toBe(502)->and(adfsGatewayJson($bad)['error'])->toBe('auth')
            ->and(Keys::gatewayFor(Principal::forUser(5))->apiKey())->toBe('gw-pasted-key-00000001');

        // Too short to be a key: refused before anything is sent.
        expect((new GatewayController())->key(Request::create('/gateway/key', 'POST', ['scope' => 'site', 'key' => 'x']))->getStatusCode())->toBe(422);
    });

    it('keeps the permissions: the site key and the choice to pay need manage, a manager’s own key spend', function () {
        adfsUser(6, ['adataforseo', 'adataforseo_spend']);
        AdfsTestCore::$loginUserId = 6;
        $project = adfsProject();
        adfsGateway();

        $site = (new GatewayController())->key(Request::create('/gateway/key', 'POST', ['scope' => 'site', 'key' => 'gw-pasted-key-00000003']));

        expect($site->getStatusCode())->toBe(403)->and(EventLog::$lines)->not->toBeEmpty()->and(Key::query()->count())->toBe(0);

        $own = (new GatewayController())->key(Request::create('/gateway/key', 'POST', ['scope' => 'user', 'key' => 'gw-pasted-key-00000003']));

        // Saved for this manager, but changing how the project pays is a project setting.
        expect($own->getStatusCode())->toBe(200)->and(Key::query()->first()->user_id)->toBe(6)->and($project->refresh()->billing)->toBe('own');

        adfsUser(8, ['adataforseo']);
        adfsGatewayProject(['domain_key' => 'other']);
        expect(fn () => (new Jobs())->start('audit', Projects::current('other'), [], Actor::for(8)))->toThrow(WorkbenchException::class);
    });

    it('keeps euros and dollars apart in the numbers the page shows', function () {
        $project = adfsGatewayProject();
        Call::query()->create(['project_id' => $project->id, 'endpoint' => 'serp/x', 'cost' => 3, 'currency' => 'USD', 'fetched_at' => Carbon::now()]);
        Call::query()->create(['project_id' => $project->id, 'endpoint' => 'gateway/seo/audit', 'cost' => 0.5, 'currency' => 'EUR', 'env' => 'gateway', 'fetched_at' => Carbon::now()]);

        $gateway = Projects::present($project);
        $project->forceFill(['billing' => 'own'])->save();
        $own = Projects::present($project->refresh());

        expect($gateway['budget']['spent_month'])->toBe(0.5)->and($gateway['budget']['currency'])->toBe('EUR')->and($gateway['billing'])->toBe('gateway')
            ->and($own['budget']['spent_month'])->toBe(3.0)->and($own['budget']['currency'])->toBe('USD')
            ->and((new Projects())->update($project, ['billing' => 'nonsense'], Actor::for(7))->billing)->toBe('own');
    });
});

describe('connect', function () {
    it('builds the consent URL on the configured gateway and trades the code for a key', function () {
        config(['cms.settings.aDataForSeo.gateway.url' => 'https://gw.example.test/']);

        expect(Connect::challenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'))->toBe('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM');

        $pkce = Connect::pkce();
        $url = Connect::authorizeUrl('https://example.test', 'https://example.test/manager/cb', $pkce['state'], $pkce['challenge']);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        expect($url)->toStartWith('https://gw.example.test/connect?')
            ->and($query['app'])->toBe('adataforseo')->and($query['code_challenge_method'])->toBe('S256')
            ->and($query['code_challenge'])->toBe(Connect::challenge($pkce['verifier']))
            ->and(Connect::siteOrigin())->toBe('https://example.test');

        $transport = new class implements Transport {
            public array $sent = [];

            public function send(string $method, string $url, ?string $body, array $headers, int $timeout, int $connectTimeout): array
            {
                $this->sent[] = [$url, json_decode((string) $body, true)];

                return str_contains((string) $body, '"old"')
                    ? [400, json_encode(['error' => 'invalid_grant', 'error_description' => 'The code was already used.'])]
                    : [200, json_encode(['access_token' => 'gw-issued-key-0001', 'token_type' => 'Bearer'])];
            }
        };

        expect((new Connect($transport))->exchange('c', 'v', 'https://example.test/cb')['access_token'])->toBe('gw-issued-key-0001')
            ->and($transport->sent[0][0])->toBe('https://gw.example.test/connect/token')
            ->and($transport->sent[0][1])->toMatchArray(['grant_type' => 'authorization_code', 'code_verifier' => 'v']);

        expect(fn () => (new Connect($transport))->exchange('old', 'v', 'https://example.test/cb'))->toThrow(ApiException::class, 'already used');
    });

    it('runs the round trip: start, back with a code, a verified key saved and the project switched; a wrong state saves nothing', function () {
        adfsUser(5, ['adataforseo', 'adataforseo_spend', 'adataforseo_manage']);
        AdfsTestCore::$loginUserId = 5;
        $project = adfsProject();
        $gateway = adfsGateway();
        // A controller per request, as in production: it remembers who the manager is.
        $make = static fn () => new class extends GatewayController {
            protected function callbackUrl(): string
            {
                return 'https://example.test/manager/cb';
            }

            protected function indexUrl(): string
            {
                return 'https://example.test/manager/modules/abc';
            }

            protected function managerUrl(): string
            {
                return 'https://example.test/manager/';
            }

            protected function connector(): Connect
            {
                return new Connect(new class implements Transport {
                    public function send(string $method, string $url, ?string $body, array $headers, int $timeout, int $connectTimeout): array
                    {
                        return [200, json_encode(['access_token' => 'gw-issued-key-0002'])];
                    }
                });
            }
        };
        $controller = $make();

        $start = $controller->connect(Request::create('/gateway/connect', 'GET', ['scope' => 'site']));
        parse_str((string) parse_url($start->getTargetUrl(), PHP_URL_QUERY), $query);

        expect($start->getTargetUrl())->toStartWith('https://ai.artur.work/connect?')->and($query['redirect_uri'])->toBe('https://example.test/manager/cb')
            ->and($_SESSION[GatewayController::SESSION_KEY]['state'])->toBe($query['state']);

        $wrong = $make()->connectCallback(Request::create('/cb', 'GET', ['code' => 'c', 'state' => 'forged']));

        expect($wrong->getTargetUrl())->toContain('gateway=failed')->and(Key::query()->count())->toBe(0);

        $controller->connect(Request::create('/gateway/connect', 'GET', ['scope' => 'site']));
        $state = $_SESSION[GatewayController::SESSION_KEY]['state'];
        $back = $make()->connectCallback(Request::create('/cb', 'GET', ['code' => 'c', 'state' => $state]));

        // Back inside the manager (its deep link opens the module in a tab), not the bare module route.
        expect($back->getTargetUrl())->toStartWith('https://example.test/manager/#modules/abc?gateway=connected&message=')
            ->and($back->getTargetUrl())->toEndWith('#account')
            ->and(Keys::gatewayFor(Principal::forUser(5))->apiKey())->toBe('gw-issued-key-0002')
            ->and(Key::query()->first()->balance)->toBe(12.5)
            ->and($project->refresh()->billing)->toBe('gateway')
            ->and($_SESSION[GatewayController::SESSION_KEY] ?? null)->toBeNull();

        // A manager who lost the right in the meantime gets nothing from a stale session.
        $controller->connect(Request::create('/gateway/connect', 'GET', ['scope' => 'site']));
        adfsRevoke(5, 'adataforseo_manage');
        Key::query()->delete();
        $stale = $make()->connectCallback(Request::create('/cb', 'GET', ['code' => 'c', 'state' => $_SESSION[GatewayController::SESSION_KEY]['state']]));

        expect($stale->getTargetUrl())->toContain('gateway=failed')->and(Key::query()->count())->toBe(0);
    });
});

describe('manager deep link', function () {
    it('turns a manager page into the manager deep link and leaves other URLs alone', function () {
        expect(Manager::framedUrl('http://localhost:8091/manager/modules/1e5d?gateway=connected#account', 'http://localhost:8091/manager/'))
            ->toBe('http://localhost:8091/manager/#modules/1e5d?gateway=connected#account')
            ->and(Manager::framedUrl('https://example.test/sub/admin/modules/x', '/sub/admin/'))->toBe('/sub/admin/#modules/x')
            ->and(Manager::framedUrl('https://example.test/manager/', 'https://example.test/manager/'))->toBe('https://example.test/manager/')
            ->and(Manager::framedUrl('https://example.test/managerx/modules/x', 'https://example.test/manager/'))->toBe('https://example.test/managerx/modules/x')
            ->and(Manager::framedUrl('https://example.test/other', 'https://example.test/manager/'))->toBe('https://example.test/other');
    });
});

describe('connect relay', function () {
    it('hands the gateway a redirect_uri on the front end that names only a path on this host', function () {
        $uri = ConnectRelay::redirectUri('http://localhost:8091/', 'http://localhost:8091/manager/modules/abc123/gateway/connect/callback');
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        expect($uri)->toStartWith('http://localhost:8091/adataforseo/gateway-return?m=')
            ->and($query['m'])->toBe('/manager/modules/abc123/gateway/connect/callback')
            ->and(ConnectRelay::matches('/adataforseo/gateway-return?code=1'))->toBeTrue()
            ->and(ConnectRelay::matches('/adataforseo/ping/token'))->toBeFalse();
    });

    it('forwards code and state to the manager callback and nothing else', function () {
        $target = ConnectRelay::target(['m' => '/manager/modules/abc123/gateway/connect/callback', 'code' => 'c0de', 'state' => 'st', 'error' => 'access_denied', 'junk' => 'x']);

        expect($target)->toBe('/manager/modules/abc123/gateway/connect/callback?code=c0de&state=st&error=access_denied');
    });

    it('never forwards anywhere but a gateway callback path on the same host', function () {
        foreach (['https://evil.test/gateway/connect/callback', '//evil.test/gateway/connect/callback', '/manager/../etc/gateway/connect/callback',
                     '/manager//x/gateway/connect/callback', '/manager/modules/x/other', 'javascript:alert(1)', ''] as $bad) {
            expect(ConnectRelay::target(['m' => $bad, 'code' => 'c', 'state' => 's']))->toBeNull();
        }

        expect(ConnectRelay::target(['code' => 'c']))->toBeNull()->and(ConnectRelay::target(['m' => ['array']]))->toBeNull();
    });

    it('answers with a page that asks for no Referer and escapes the target', function () {
        $page = ConnectRelay::page('/manager/modules/a/gateway/connect/callback?code=a%22b&state=s');

        expect($page)->toContain('<meta name="referrer" content="no-referrer">')->and($page)->toContain('location.replace(')
            ->and($page)->not->toContain('a"b');
    });

    it('is what the controller registers with the gateway', function () {
        $controller = new class extends GatewayController {
            public function redirect(): string
            {
                return $this->callbackUrl();
            }

            protected function managerCallbackUrl(): string
            {
                return 'http://example.test/manager/modules/x/gateway/connect/callback';
            }
        };

        expect($controller->redirect())->toContain('/adataforseo/gateway-return?m=');
    });
});

describe('translations', function () {
    it('has every key the gateway mode names', function () {
        $english = require dirname(__DIR__, 2) . '/lang/en/global.php';
        $keys = [
            'ui_env_gateway', 'ui_wiz_gateway', 'ui_wiz_gateway_help', 'ui_gateway_title', 'ui_gateway_connect', 'ui_gateway_connect_help', 'ui_gateway_paste',
            'ui_gateway_present', 'ui_gateway_missing', 'ui_gateway_connected', 'ui_gateway_topup', 'ui_gateway_cap', 'ui_gateway_limits', 'ui_gateway_use',
            'ui_gateway_use_own', 'ui_gateway_remove', 'ui_billing_title', 'ui_billing_own', 'ui_billing_own_help', 'ui_billing_gateway', 'ui_billing_gateway_help',
            'ui_billing_saved', 'field_gateway_key', 'ui_gateway_key_help', 'ui_gateway_needs_own', 'ui_gateway_explorer', 'ui_difficulty_keywords',
            'ui_difficulty_keywords_help', 'ui_value', 'ui_serp_aggregates', 'ui_serp_aggregates_help', 'ui_agg_results', 'ui_agg_sites', 'ui_agg_ugc', 'ui_agg_unknown',
            'ui_agg_strong', 'ui_agg_strongest', 'ui_agg_median', 'ui_agg_other_language', 'refusal_gateway_balance', 'refusal_key_cap', 'error_gateway_unsupported',
            'error_gateway_no_targets', 'error_gateway_auth', 'error_gateway_location', 'error_gateway_invalid', 'error_gateway_unavailable', 'error_gateway_busy',
            'error_gateway_task_gone', 'error_gateway_http', 'error_gateway_no_task', 'error_gateway_task_failed', 'error_gateway_expired', 'error_gateway_key',
            'error_gateway_connect', 'error_gateway_connect_expired', 'error_gateway_connect_denied', 'code_audit_other', 'card_audit_other_title',
            'card_audit_other_why', 'manual_audit_other',
        ];

        expect(array_values(array_filter($keys, static fn ($k) => !isset($english[$k]) || trim((string) $english[$k]) === '')))->toBe([]);
    });
});
