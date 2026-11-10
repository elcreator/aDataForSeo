<?php

namespace Elcreator\aDataForSeo\Api;

use Elcreator\aDataForSeo\Support\Lang;
use RuntimeException;

/**
 * A call that did not happen or did not work.
 *
 * `retryable` decides between "try again after a back-off" (HTTP 5xx,
 * timeouts, 429, codes in `api.retryable_codes`) and "fail the step with this
 * message" (auth, validation). A budget refusal is `refusal` set to its code
 * (`job_cap`, `monthly_cap`, `balance`, `approval_needed`) and is never retried.
 * Messages never carry credentials.
 */
class ApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $error = 'api_error',
        public readonly bool $retryable = false,
        public readonly int $statusCode = 0,
        public readonly ?string $refusal = null
    ) {
        parent::__construct($message);
    }

    public static function refused(string $refusal): self
    {
        return new self(Lang::get('aDataForSeo::global.refusal_' . $refusal), 'refused', false, 0, $refusal);
    }

    public static function transport(string $detail): self
    {
        return new self(Lang::get('aDataForSeo::global.error_transport', ['detail' => $detail]), 'transport', true);
    }

    public static function http(int $status): self
    {
        if ($status === 401 || $status === 403) {
            return new self(Lang::get('aDataForSeo::global.error_auth'), 'auth', false, $status);
        }

        return new self(Lang::get('aDataForSeo::global.error_http', ['status' => $status]), 'http', $status >= 500 || $status === 429, $status);
    }

    public static function api(int $code, string $message, bool $retryable): self
    {
        if (in_array($code, [40100, 40101, 40102, 40104], true)) {
            return new self(Lang::get('aDataForSeo::global.error_auth'), 'auth', false, $code);
        }

        if (in_array($code, [40200, 40210], true) && !$retryable) {
            return new self(Lang::get('aDataForSeo::global.refusal_balance'), 'balance', false, $code, 'balance');
        }

        // DataForSEO's own message is plain text about the request, never about the account secret.
        return new self(Lang::get('aDataForSeo::global.error_api', ['code' => $code, 'detail' => mb_substr($message, 0, 200)]), 'api', $retryable, $code);
    }
}
