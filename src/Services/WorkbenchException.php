<?php

namespace Elcreator\aDataForSeo\Services;

use Elcreator\aDataForSeo\Api\ApiException;
use Elcreator\aDataForSeo\Support\EventLog;
use Elcreator\aDataForSeo\Support\Lang;
use RuntimeException;

/**
 * A refusal from the module, in the one shape every surface reports it:
 * `error` is the machine code, `status` the HTTP status, the message is
 * already translated, `errors` maps fields to messages. Refusals are logged
 * (hard rule 12) by the factories that represent one.
 */
class WorkbenchException extends RuntimeException
{
    /** @var array<string, string> */
    public array $errors = [];

    public function __construct(public readonly string $error, string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public static function forbidden(string $what = 'request'): self
    {
        EventLog::refusal($what, 'forbidden');

        return new self('forbidden', Lang::get('aDataForSeo::global.error_forbidden'), 403);
    }

    public static function notFound(string $what): self
    {
        return new self('not_found', Lang::get('aDataForSeo::global.error_not_found', ['what' => $what]), 404);
    }

    public static function invalid(string $key, array $replace = []): self
    {
        return new self($key, Lang::get('aDataForSeo::global.' . $key, $replace), 422);
    }

    /** A state or budget refusal (409). */
    public static function refused(string $key, array $replace = [], array $context = []): self
    {
        EventLog::refusal($key, 'refused', $context);

        return new self($key, Lang::get('aDataForSeo::global.' . $key, $replace), 409);
    }

    /** @param array<string, string> $errors field => translated message */
    public static function fields(array $errors): self
    {
        $e = new self('invalid', Lang::get('aDataForSeo::global.error_invalid'), 422);
        $e->errors = $errors;

        return $e;
    }

    public static function fromApi(ApiException $e): self
    {
        if ($e->refusal !== null) {
            return new self($e->refusal, $e->getMessage(), 409);
        }

        return new self($e->error, $e->getMessage(), 502);
    }

    public function toArray(): array
    {
        return array_filter([
            'error' => $this->error,
            'message' => $this->getMessage(),
            'errors' => $this->errors ?: null,
        ], static fn ($v) => $v !== null);
    }
}
