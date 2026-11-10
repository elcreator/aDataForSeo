<?php

namespace Elcreator\aDataForSeo\Api;

use Elcreator\aDataForSeo\Support\Config;

/**
 * The only way a DataForSEO call is made.
 *
 * call() = cache lookup → estimate → budget check → HTTP → ledger → cache
 * write. The ledger row is written before the answer is handed back, so a
 * crash while using it never loses what was paid. `task_get` answers repeat
 * the price of the task charged at `task_post` and are booked at 0.
 */
final class Client
{
    public const PROD_URL = 'https://api.dataforseo.com/v3';
    public const SANDBOX_URL = 'https://sandbox.dataforseo.com/v3';

    private static ?Transport $override = null;

    private readonly string $env;

    private readonly Transport $transport;

    private readonly Ledger $ledger;

    private readonly Cache $cache;

    private ?Estimator $estimator = null;

    public function __construct(private readonly Credentials $credentials, ?string $env = null, ?Transport $transport = null)
    {
        $env = $env ?? $credentials->env;
        $this->env = in_array($env, ['prod', 'sandbox', 'demo'], true) ? $env : 'prod';
        $this->transport = $transport ?? self::$override ?? ($this->env === 'demo' ? new DemoTransport() : new CurlTransport());
        $this->ledger = new Ledger();
        $this->cache = new Cache();
    }

    /** Test seam: every client built afterwards talks to this transport. */
    public static function useTransport(?Transport $transport): void
    {
        self::$override = $transport;
    }

    public function env(): string
    {
        return $this->env;
    }

    public function baseUrl(): string
    {
        return $this->env === 'sandbox' ? self::SANDBOX_URL : self::PROD_URL;
    }

    public function ledger(): Ledger
    {
        return $this->ledger;
    }

    public function estimator(): Estimator
    {
        return $this->estimator ??= new Estimator(
            new Pricing(function (): ?array {
                $data = $this->request('GET', 'appendix/user_data', null);

                return is_array($data['tasks'][0]['result'][0]['price'] ?? null) ? $data['tasks'][0]['result'][0]['price'] : null;
            }),
            $this->ledger
        );
    }

    /**
     * @param array<int, array<string, mixed>>|null $tasks null for a GET
     * @param array{expect_items?: int, cache?: bool, depth?: int} $options
     * @throws ApiException
     */
    public function call(string $endpoint, ?array $tasks, Context $ctx, array $options = []): Response
    {
        $endpoint = Endpoints::normalise($endpoint);
        $useCache = ($options['cache'] ?? true) && Endpoints::cacheable($endpoint);
        $key = $useCache ? Cache::key($this->env, $endpoint, $tasks) : null;
        $depth = Estimator::maxDepth((array) $tasks);

        if ($key !== null && ($stored = $this->cache->get($key)) !== null) {
            $call = $this->ledger->record($ctx, $endpoint, 0.0, [
                'env' => $this->env,
                'cache_key' => $key,
                'cached' => true,
                'status_code' => (int) ($stored['status_code'] ?? 0),
                'tasks' => count((array) $tasks),
                'depth' => $depth,
            ]);

            return new Response($stored, true, (int) $call->getKey(), 0.0);
        }

        $estimate = $this->estimator()->estimate($endpoint, $tasks, $ctx->priority, $options['expect_items'] ?? null);
        $refusal = $this->ledger->allow($ctx, $estimate);

        if ($refusal !== null) {
            throw ApiException::refused($refusal);
        }

        $data = $this->request($tasks === null ? 'GET' : 'POST', $endpoint, $tasks);
        $response = new Response($data);
        $cost = Endpoints::isTaskGet($endpoint) ? 0.0 : $response->cost();
        $stored = null;

        $allOk = $response->tasks() !== [];

        foreach ($response->tasks() as $task) {
            $allOk = $allOk && Response::taskOk($task);
        }

        if ($key !== null && $allOk) {
            $stored = $this->cache->put($key, $endpoint, $data);
        }

        $call = $this->ledger->record($ctx, $endpoint, $cost, [
            'env' => $this->env,
            'cache_key' => $key,
            'estimate' => $estimate->amount,
            'status_code' => $response->statusCode(),
            'tasks' => count($response->tasks()),
            'items' => $response->itemCount(),
            'depth' => $depth,
            'response_path' => $stored['path'] ?? null,
            'expires_at' => $stored['expires_at'] ?? null,
        ]);

        return new Response($data, false, (int) $call->getKey(), $cost);
    }

    /** `appendix/user_data`: balance, prices, subscriptions. Free; not cached. */
    public function userData(): array
    {
        $data = $this->request('GET', 'appendix/user_data', null);
        $result = $data['tasks'][0]['result'][0] ?? null;

        if (!is_array($result)) {
            throw new ApiException('DataForSEO returned no account data.', 'api', true);
        }

        return $result;
    }

    /**
     * One HTTP exchange; top-level failures become exceptions, task-level ones stay in the answer.
     *
     * @throws ApiException
     */
    private function request(string $method, string $endpoint, ?array $tasks): array
    {
        [$status, $body] = $this->transport->send(
            $method,
            $this->baseUrl() . '/' . $endpoint,
            $tasks === null ? null : (string) json_encode(array_values($tasks), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            [
                'Authorization' => $this->credentials->authorization(),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Accept-Encoding' => 'gzip',
            ],
            (int) Config::get('api.timeout', 120),
            (int) Config::get('api.connect_timeout', 10)
        );

        $data = json_decode($body, true);

        if ($status !== 200 && (!is_array($data) || !isset($data['status_code']))) {
            throw ApiException::http($status);
        }

        if (!is_array($data)) {
            throw ApiException::transport('invalid JSON');
        }

        $code = (int) ($data['status_code'] ?? 0);

        if ($code !== Response::OK) {
            $retryable = in_array($code, array_map('intval', (array) Config::get('api.retryable_codes', [])), true) || $code >= 50000;

            throw ApiException::api($code, (string) ($data['status_message'] ?? ''), $retryable);
        }

        return $data;
    }
}
