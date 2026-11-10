<?php

namespace Elcreator\aDataForSeo\Http\Controllers;

use Elcreator\aDataForSeo\Api\ApiException;
use Elcreator\aDataForSeo\Models\Project;
use Elcreator\aDataForSeo\Services\Actor;
use Elcreator\aDataForSeo\Services\Projects;
use Elcreator\aDataForSeo\Services\WorkbenchException;
use Elcreator\aDataForSeo\Support\EventLog;
use Elcreator\aDataForSeo\Support\Lang;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin: request → one service call → JsonResponse. Never `response()` (it
 * needs a booted container). `{ok:true, ...}` or `{ok:false, error, message
 * [, errors]}` with 403/404/409/422/502.
 */
abstract class Controller
{
    private ?Actor $actor = null;

    protected function actor(): Actor
    {
        return $this->actor ??= Actor::current();
    }

    protected function currentProject(): Project
    {
        return Projects::current();
    }

    /**
     * @param callable(): array $call
     * @param 'use'|'spend'|'manage' $level
     */
    protected function respond(callable $call, string $level = 'use'): JsonResponse
    {
        $allowed = match ($level) {
            'spend' => $this->actor()->maySpend(),
            'manage' => $this->actor()->mayManage(),
            default => $this->actor()->mayUse(),
        };

        if (!$allowed) {
            EventLog::refusal('request (' . $level . ')', 'forbidden', ['user' => $this->actor()->userId()]);

            return $this->fail('forbidden', Lang::get('aDataForSeo::global.error_forbidden'), 403);
        }

        try {
            return new JsonResponse(['ok' => true] + $call());
        } catch (WorkbenchException $e) {
            return new JsonResponse(['ok' => false] + $e->toArray(), $e->status);
        } catch (ApiException $e) {
            $e2 = WorkbenchException::fromApi($e);

            return new JsonResponse(['ok' => false] + $e2->toArray(), $e2->status);
        }
    }

    protected function fail(string $error, string $message, int $status = 422): JsonResponse
    {
        return new JsonResponse(['ok' => false, 'error' => $error, 'message' => $message], $status);
    }

    protected function csrfToken(): string
    {
        try {
            return (string) csrf_token();
        } catch (\Throwable) {
            return '';
        }
    }

    protected function text(Request $request, string $key, string $default = ''): string
    {
        $value = $request->input($key, $default);

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    protected function flag(Request $request, string $key, bool $default = false): bool
    {
        $value = $request->input($key);

        return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    protected function listOf(Request $request, string $key): array
    {
        $value = $request->input($key, []);

        return is_array($value) ? $value : [];
    }
}
