<?php

namespace Elcreator\aDataForSeo\Gateway;

use Elcreator\aDataForSeo\Api\ApiException;
use Elcreator\aDataForSeo\Api\Context;
use Elcreator\aDataForSeo\Api\CurlTransport;
use Elcreator\aDataForSeo\Api\Ledger;
use Elcreator\aDataForSeo\Api\Transport;
use Elcreator\aDataForSeo\Models\Call;
use Elcreator\aDataForSeo\Models\Key;
use Elcreator\aDataForSeo\Support\Config;
use Elcreator\aDataForSeo\Support\Lang;

/**
 * The ai.artur.work gateway, as far as this plugin uses it: the analytics under
 * `/v1/seo`, the account and the price list.
 *
 * It sits beside {@see \Elcreator\aDataForSeo\Api\Client}, not under it: the gateway
 * answers with processed analytics, never DataForSEO's records, so it replaces whole
 * plans rather than single endpoint calls. The money contract is the same, though:
 * `submit()` asks for a price first (`quote: true`, free) and refuses over budget, and
 * what the gateway reports as charged is booked in the ledger by `record()`.
 *
 * One instance carries one key, which is the manager's; a transport seam
 * ({@see self::useTransport()}) lets tests answer in the gateway's own envelopes.
 */
final class Client
{
    /** Operation name => path under `/v1`. The only operations this plugin runs. */
    public const PATHS = [
        'index-coverage' => 'seo/index-coverage',
        'audit' => 'seo/audit',
        'keywords/difficulty' => 'seo/keywords/difficulty',
    ];

    private static ?Transport $override = null;

    private readonly Transport $transport;

    private ?Estimator $estimator = null;

    public function __construct(private readonly Credentials $credentials, ?Transport $transport = null)
    {
        $this->transport = $transport ?? self::$override ?? new CurlTransport();
    }

    /** Test seam: every client built afterwards talks to this transport. */
    public static function useTransport(?Transport $transport): void
    {
        self::$override = $transport;
    }

    public function estimator(): Estimator
    {
        return $this->estimator ??= new Estimator($this);
    }

    /** `GET /v1/account`: the balance in euros, the key's cap, where to top up. Free; also how a key is verified. */
    public function account(): array
    {
        return $this->request('GET', 'account');
    }

    /** `GET /v1/seo/prices`: what each operation costs per unit, in euros. Free. */
    public function prices(): array
    {
        return $this->request('GET', 'seo/prices');
    }

    /**
     * What a request would cost, from the gateway itself. Runs nothing, charges nothing,
     * creates no run. `low`/`high` null = the gateway cannot price it: unknown, never 0.
     *
     * @return array{low: ?float, high: ?float, currency: string}
     */
    public function quote(string $op, array $body): array
    {
        $answer = $this->request('POST', self::path($op), ['quote' => true] + $body);

        return [
            'low' => is_numeric($answer['low'] ?? null) ? (float) $answer['low'] : null,
            'high' => is_numeric($answer['high'] ?? null) ? (float) $answer['high'] : null,
            'currency' => (string) ($answer['currency'] ?? 'EUR'),
        ];
    }

    /**
     * Start a queued analysis. Priced and checked against the caps first; the idempotency
     * key makes a repeat of this call, from a worker that died before it wrote the task id
     * down, return the stored answer instead of running (and charging) twice.
     *
     * @return array{taskId: string, state: string}
     * @throws ApiException
     */
    public function submit(string $op, array $body, string $idempotencyKey, Context $ctx): array
    {
        $estimate = $this->estimator()->quote($op, $body);
        $refusal = (new Ledger())->allow($ctx, $estimate);

        if ($refusal !== null) {
            throw $refusal === 'balance' ? self::balanceRefusal() : ApiException::refused($refusal);
        }

        $answer = $this->request('POST', self::path($op), $body, ['Idempotency-Key' => $idempotencyKey]);

        if (!is_string($answer['taskId'] ?? null) || $answer['taskId'] === '') {
            throw ApiException::transport('no task id in the answer');
        }

        return ['taskId' => $answer['taskId'], 'state' => (string) ($answer['state'] ?? 'running')];
    }

    /**
     * `GET /v1/seo/tasks/{id}`: `{taskId, operation, state: running|done|failed, result?, expired?, chargedEur?, error?}`.
     *
     * @throws ApiException
     */
    public function task(string $taskId): array
    {
        return $this->request('GET', 'seo/tasks/' . rawurlencode($taskId));
    }

    /**
     * Book what the gateway charged for a finished task, once: a polled task is read again
     * by a worker that crashed after booking, and the ledger must not count it twice.
     */
    public function record(Context $ctx, string $op, float $chargedEur, string $taskId, int $items = 0, ?float $estimate = null): ?Call
    {
        $key = sha1('gateway:' . $taskId);

        if (Call::query()->where('cache_key', $key)->exists()) {
            return null;
        }

        $call = (new Ledger())->record($ctx, 'gateway/' . self::path($op), $chargedEur, [
            'env' => 'gateway',
            'currency' => 'EUR',
            'cache_key' => $key,
            'estimate' => $estimate,
            'status_code' => 200,
            'tasks' => 1,
            'items' => $items,
        ]);

        $this->spendStoredBalance($chargedEur);

        return $call;
    }

    /** The balance shown beside the key follows what was just charged; "Check again" reads the real one. */
    private function spendStoredBalance(float $chargedEur): void
    {
        if ($this->credentials->keyId === null || $chargedEur <= 0) {
            return;
        }

        $key = Key::query()->find($this->credentials->keyId);

        if ($key !== null && $key->balance !== null) {
            $key->forceFill(['balance' => round(max(0.0, (float) $key->balance - $chargedEur), 6)])->save();
        }
    }

    /** The refusal a "not enough balance" gets in gateway mode: it names the right balance. */
    public static function balanceRefusal(): ApiException
    {
        return new ApiException(Lang::get('aDataForSeo::global.refusal_gateway_balance'), 'balance', false, 402, 'balance');
    }

    /** @throws \InvalidArgumentException for an operation this plugin does not run */
    public static function path(string $op): string
    {
        return self::PATHS[$op] ?? throw new \InvalidArgumentException('Unknown gateway operation ' . $op);
    }

    /**
     * The gateway's `ApiError` envelope as the plugin's own exception: a refusal where money
     * is the reason, retryable where waiting helps, a translated message either way.
     * `error.code` is the machine code where there is one, else `error.type`.
     */
    public static function failure(int $status, ?array $body): ApiException
    {
        $error = is_array($body['error'] ?? null) ? $body['error'] : [];
        $code = (string) ($error['code'] ?? $error['type'] ?? '');
        $detail = mb_substr(trim((string) ($error['message'] ?? '')), 0, 200);

        return match (true) {
            $status === 401 || $status === 403 => new ApiException(Lang::get('aDataForSeo::global.error_gateway_auth'), 'auth', false, $status),
            $status === 402 && $code === 'key_spend_cap_reached' => new ApiException(Lang::get('aDataForSeo::global.refusal_key_cap'), 'key_cap', false, 402, 'key_cap'),
            $status === 402 => self::balanceRefusal(),
            $status === 400 && $code === 'seo_location_unsupported' => new ApiException(Lang::get('aDataForSeo::global.error_gateway_location', ['detail' => $detail]), 'location', false, 400),
            $status === 404 && $code === 'task_not_found' => new ApiException(Lang::get('aDataForSeo::global.error_gateway_task_gone'), 'task_not_found', false, 404),
            $status === 409 => new ApiException(Lang::get('aDataForSeo::global.error_gateway_busy'), 'busy', true, 409),
            $status === 503 => new ApiException(Lang::get('aDataForSeo::global.error_gateway_unavailable'), 'unavailable', true, 503),
            $status >= 400 && $status < 500 && $status !== 408 && $status !== 429 => new ApiException(Lang::get('aDataForSeo::global.error_gateway_invalid', ['detail' => $detail !== '' ? $detail : 'HTTP ' . $status]), 'invalid', false, $status),
            default => new ApiException(Lang::get('aDataForSeo::global.error_gateway_http', ['status' => $status]), 'http', true, $status),
        };
    }

    /**
     * One HTTP exchange; any non-2xx answer becomes an exception.
     *
     * @param array<string, string> $headers
     * @throws ApiException
     */
    private function request(string $method, string $path, ?array $body = null, array $headers = []): array
    {
        [$status, $raw] = $this->transport->send(
            $method,
            $this->credentials->siteUrl() . '/v1/' . $path,
            $body === null ? null : (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $headers + [
                'Authorization' => 'Bearer ' . $this->credentials->apiKey(),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => 'aDataForSeo/1.0 (Evolution CMS)',
            ],
            (int) Config::get('gateway.timeout', 60),
            (int) Config::get('gateway.connect_timeout', 10)
        );

        $data = json_decode($raw, true);

        if ($status < 200 || $status >= 300) {
            throw self::failure($status, is_array($data) ? $data : null);
        }

        if (!is_array($data)) {
            throw ApiException::transport('invalid JSON');
        }

        return $data;
    }
}
