<?php

use Carbon\Carbon;
use Elcreator\aDataForSeo\Api\ApiException;
use Elcreator\aDataForSeo\Api\Cache;
use Elcreator\aDataForSeo\Api\Client;
use Elcreator\aDataForSeo\Api\Context;
use Elcreator\aDataForSeo\Api\Credentials;
use Elcreator\aDataForSeo\Api\Estimate;
use Elcreator\aDataForSeo\Api\Estimator;
use Elcreator\aDataForSeo\Api\Ledger;
use Elcreator\aDataForSeo\Api\Pricing;
use Elcreator\aDataForSeo\Models\Call;
use Elcreator\aDataForSeo\Models\Job;
use Elcreator\aDataForSeo\Support\EventLog;

/**
 * Hard rules 1–3: no call without a price, the response's cost is the truth,
 * unknown is never zero, every refusal is logged.
 */

beforeEach(fn () => adfsReset());

function adfsEstimator(): Estimator
{
    return new Estimator(new Pricing(), new Ledger());
}

function adfsClient(AdfsFakeTransport $transport): Client
{
    Client::useTransport($transport);

    return new Client(new Credentials('login@example.test', 'secret', 'prod'));
}

describe('Pricing', function () {
    it('reads the user_data price tree by endpoint and priority', function () {
        $pricing = new Pricing();

        expect($pricing->for('serp/google/organic/task_post', 1))->toBe([['cost_type' => 'per_request', 'cost' => 0.0006]])
            ->and($pricing->for('serp/google/organic/task_post', 2))->toBe([['cost_type' => 'per_request', 'cost' => 0.0012]])
            ->and($pricing->for('serp/google/organic/live/advanced', 1)[0]['cost'])->toBe(0.002)
            ->and($pricing->for('nothing/like/this', 1))->toBeNull();
    });

    it('uses a stale table when a refresh fails, and stores a fresh one', function () {
        Pricing::useTable(null);
        Pricing::store(['serp' => ['google' => ['organic' => ['task_post' => ['priority_normal' => [['cost_type' => 'per_request', 'cost' => 0.5]]]]]]]);
        $file = Pricing::file();
        $data = json_decode((string) file_get_contents($file), true);
        $data['fetched_at'] = time() - 999999;
        file_put_contents($file, json_encode($data));

        $failing = new Pricing(static fn () => throw new RuntimeException('down'));

        expect($failing->for('serp/google/organic/task_post')[0]['cost'])->toBe(0.5);
    });
});

describe('Estimator', function () {
    it('prices per request times tasks', function () {
        $estimate = adfsEstimator()->estimate('serp/google/organic/task_post', [['keyword' => 'a', 'depth' => 10], ['keyword' => 'b', 'depth' => 10]]);

        expect($estimate->amount)->toBe(0.0012)->and($estimate->exact)->toBeTrue();
    });

    it('adds per result times the expected items', function () {
        $estimate = adfsEstimator()->estimate('dataforseo_labs/google/keyword_suggestions/live', [['keyword' => 'byty atlantida', 'limit' => 100]]);

        expect($estimate->amount)->toBe(round(0.012 + 0.00012 * 100, 6));
    });

    it('prices a crawl by its pages', function () {
        expect(adfsEstimator()->estimate('on_page/task_post', [['target' => 'x.cz', 'max_crawl_pages' => 200]])->amount)->toBe(0.03);
    });

    it('treats free endpoints as free and unpriced ones as unknown, never zero', function () {
        expect(adfsEstimator()->estimate('serp/google/organic/task_get/regular/abc', null)->amount)->toBe(0.0)
            ->and(adfsEstimator()->estimate('appendix/user_data', null)->exact)->toBeTrue();

        $unknown = adfsEstimator()->estimate('content_analysis/search/live', [['keyword' => 'x']]);
        expect($unknown->amount)->toBeNull()->and($unknown->isUnknown())->toBeTrue()->and($unknown->ceiling())->toBeNull();
    });

    it('is unknown for an unknowable item count without history, a range with it', function () {
        $tasks = [['target' => 'x.cz', 'location_code' => 2203]];
        expect(adfsEstimator()->estimate('dataforseo_labs/google/ranked_keywords/live', $tasks)->amount)->toBeNull();

        foreach ([0.02, 0.04] as $cost) {
            Call::query()->create(['endpoint' => 'dataforseo_labs/google/ranked_keywords/live', 'cost' => $cost, 'tasks' => 1, 'cached' => false, 'fetched_at' => now()]);
        }

        $range = adfsEstimator()->estimate('dataforseo_labs/google/ranked_keywords/live', $tasks);
        expect($range->amount)->toBe(0.03)->and($range->low)->toBe(0.02)->and($range->high)->toBe(0.04)->and($range->basis)->toBe('history');
    });

    it('calibrates deep SERPs from the ledger for the same depth only', function () {
        $deep = [['keyword' => 'site:x.cz', 'depth' => 100]];
        expect(adfsEstimator()->estimate('serp/google/organic/task_post', $deep)->amount)->toBeNull();

        Call::query()->create(['endpoint' => 'serp/google/organic/task_post', 'cost' => 0.003, 'tasks' => 1, 'depth' => 100, 'cached' => false, 'fetched_at' => now()]);
        Call::query()->create(['endpoint' => 'serp/google/organic/task_post', 'cost' => 0.9, 'tasks' => 1, 'depth' => 700, 'cached' => false, 'fetched_at' => now()]);

        expect(adfsEstimator()->estimate('serp/google/organic/task_post', $deep)->amount)->toBe(0.003);
    });

    it('sums estimates and lets one unknown part poison the total', function () {
        expect(Estimate::sum([Estimate::exact(0.1), Estimate::exact(0.2)])->amount)->toBe(0.3)
            ->and(Estimate::sum([Estimate::exact(0.1), Estimate::unknown()])->amount)->toBeNull()
            ->and(Estimate::sum([Estimate::exact(0.1), Estimate::range(0.2, 0.1, 0.5)])->high)->toBe(0.6);
    });
});

describe('Ledger', function () {
    it('refuses over the job cap, the monthly cap and the balance, and logs each refusal', function () {
        $ledger = new Ledger();
        Call::query()->create(['project_id' => 9, 'job_id' => 5, 'endpoint' => 'x/y', 'cost' => 0.9, 'fetched_at' => now()]);

        expect($ledger->allow(new Context(9, 5, null, null, 1.0), Estimate::exact(0.2)))->toBe('job_cap')
            ->and($ledger->allow(new Context(9, 6, null, null, 10.0, 1.0), Estimate::exact(0.2)))->toBe('monthly_cap')
            ->and($ledger->allow(new Context(9, 6, null, null, 10.0, 10.0, false, 1, 0.1), Estimate::exact(0.2)))->toBe('balance')
            ->and($ledger->allow(new Context(9, 6, null, null, 10.0, 10.0), Estimate::exact(0.2)))->toBeNull()
            ->and(count(EventLog::$lines))->toBe(3);
    });

    it('needs approval for an unknown estimate, and still honours a spent cap when approved', function () {
        $ledger = new Ledger();

        expect($ledger->allow(new Context(9, 5, null, null, 1.0), Estimate::unknown()))->toBe('approval_needed')
            ->and($ledger->allow(new Context(9, 5, null, null, 1.0, null, true), Estimate::unknown()))->toBeNull();

        Call::query()->create(['project_id' => 9, 'job_id' => 5, 'endpoint' => 'x/y', 'cost' => 1.0, 'fetched_at' => now()]);
        expect($ledger->allow(new Context(9, 5, null, null, 1.0, null, true), Estimate::unknown()))->toBe('job_cap');
    });

    it('counts only this month for the monthly cap', function () {
        Call::query()->create(['project_id' => 9, 'endpoint' => 'x/y', 'cost' => 4.0, 'fetched_at' => Carbon::now()->subMonths(2)]);

        expect((new Ledger())->spentMonth(9))->toBe(0.0);
    });
});

describe('Cache', function () {
    it('normalises keys: order, keyword case and order, tag and callbacks do not matter', function () {
        $a = Cache::key('prod', 'x/y/live', [['keywords' => ['B', 'a'], 'location_code' => 1, 'tag' => 'job:1', 'pingback_url' => 'https://a']]);
        $b = Cache::key('prod', 'x/y/live', [['location_code' => 1, 'keywords' => ['a', 'b'], 'tag' => 'job:2']]);
        $c = Cache::key('sandbox', 'x/y/live', [['location_code' => 1, 'keywords' => ['a', 'b']]]);
        $d = Cache::key('prod', 'x/y/live', [['location_code' => 2, 'keywords' => ['a', 'b']]]);

        expect($a)->toBe($b)->and($a)->not->toBe($c)->and($a)->not->toBe($d);
    });

    it('answers a repeated question from the cache, free, and still books it', function () {
        $transport = new AdfsFakeTransport(['#keyword_suggestions#' => fn () => adfsEnvelope([adfsTask([['items' => [['keyword' => 'a']]]], [], 20000, 0.024)], 0.024)]);
        $client = adfsClient($transport);
        $tasks = [['keyword' => 'byty', 'limit' => 100, 'location_code' => 2203, 'language_code' => 'cs']];
        $ctx = new Context(1, null, null, null, null, 10.0);

        $first = $client->call('dataforseo_labs/google/keyword_suggestions/live', $tasks, $ctx);
        $second = $client->call('dataforseo_labs/google/keyword_suggestions/live', $tasks, $ctx);

        expect($first->cached)->toBeFalse()->and($first->recordedCost)->toBe(0.024)
            ->and($second->cached)->toBeTrue()->and($second->recordedCost)->toBe(0.0)
            ->and($transport->count('#keyword_suggestions#'))->toBe(1)
            ->and(Call::query()->count())->toBe(2)
            ->and(Call::query()->where('cached', true)->value('cost'))->toBe(0.0);
    });
});

describe('Client', function () {
    it('books task_get at zero: it repeats the price charged at task_post', function () {
        $transport = new AdfsFakeTransport(['#task_get#' => fn () => adfsEnvelope([adfsTask([['items' => []]], [], 20000, 0.0006)], 0.0006)]);
        $job = Job::query()->create(['project_id' => 1, 'user_id' => 1, 'kind' => 'coverage', 'status' => 'running']);

        adfsClient($transport)->call('serp/google/organic/task_get/regular/abc', null, new Context(1, $job->id));

        expect(Call::query()->value('cost'))->toBe(0.0)->and($job->fresh()->spent)->toBe(0.0);
    });

    it('records the real cost before returning and adds it to the job', function () {
        $transport = new AdfsFakeTransport(['#task_post#' => fn () => adfsEnvelope([adfsTask([], [], 20100, 0.0006, 'id1'), adfsTask([], [], 20100, 0.0006, 'id2')], 0.0012)]);
        $job = Job::query()->create(['project_id' => 1, 'user_id' => 1, 'kind' => 'coverage', 'status' => 'running']);

        $response = adfsClient($transport)->call('serp/google/organic/task_post', [['keyword' => 'a', 'depth' => 10], ['keyword' => 'b', 'depth' => 10]], new Context(1, $job->id, null, null, 1.0));

        expect($response->ok(0))->toBeTrue()->and($job->fresh()->spent)->toBe(0.0012)
            ->and(Call::query()->value('estimate'))->toBe(0.0012);
    });

    it('reports a failed task inside a 200 answer instead of throwing', function () {
        $transport = new AdfsFakeTransport(['#live#' => fn () => adfsEnvelope([adfsTask([], [], 40501, 0.0)])]);

        $response = adfsClient($transport)->call('dataforseo_labs/google/keyword_overview/live', [['keywords' => ['a'], 'limit' => 1]], new Context(1));

        expect($response->ok())->toBeFalse()->and($response->task()['status_code'])->toBe(40501);
    });

    it('throws a non-retryable error for bad credentials and a retryable one for server errors', function () {
        $auth = new AdfsFakeTransport(['#.#' => fn () => [401, '{"status_code":40100,"status_message":"You are not authorized"}']]);
        $down = new AdfsFakeTransport(['#.#' => fn () => [502, 'Bad gateway']]);

        try {
            adfsClient($auth)->call('appendix/user_data', null, new Context());
            $this->fail('expected an exception');
        } catch (ApiException $e) {
            expect($e->retryable)->toBeFalse()->and($e->error)->toBe('auth')->and($e->getMessage())->not->toContain('secret');
        }

        try {
            adfsClient($down)->call('appendix/user_data', null, new Context());
            $this->fail('expected an exception');
        } catch (ApiException $e) {
            expect($e->retryable)->toBeTrue();
        }
    });

    it('refuses before calling when the budget does not allow it', function () {
        $transport = new AdfsFakeTransport(['#.#' => fn () => adfsEnvelope([])]);

        expect(fn () => adfsClient($transport)->call('content_analysis/search/live', [['keyword' => 'x']], new Context(1)))
            ->toThrow(ApiException::class)
            ->and($transport->requests)->toBe([]);
    });

    it('never exposes the password', function () {
        $credentials = new Credentials('someone@example.test', 'hunter2', 'prod');

        expect(print_r($credentials, true))->not->toContain('hunter2')
            ->and($credentials->maskedLogin())->toBe('s••••••@example.test')
            ->and(fn () => serialize($credentials))->toThrow(LogicException::class);
    });
});
