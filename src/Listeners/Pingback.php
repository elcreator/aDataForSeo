<?php

namespace Elcreator\aDataForSeo\Listeners;

use Carbon\Carbon;
use Elcreator\aDataForSeo\Engine\Pingback as Token;
use Elcreator\aDataForSeo\Models\Job;
use Elcreator\aDataForSeo\Services\Jobs;
use Throwable;

/**
 * GET /adataforseo/ping/{token}?id=$id&tag=$tag — DataForSEO saying a task is
 * ready. Only wakes the job the token belongs to; never reads results from
 * the request, and answers 204 to everything, so it tells a caller nothing.
 */
final class Pingback
{
    public static function handle($params = []): void
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $token = Token::tokenFromPath($uri);

        if ($token === null) {
            return;
        }

        try {
            self::wake($token);
        } catch (Throwable) {
            // Same answer whatever happened.
        }

        if (!headers_sent()) {
            http_response_code(204);
        }

        exit;
    }

    /** @return bool whether a job was woken (for tests; the HTTP answer never says) */
    public static function wake(string $token): bool
    {
        foreach (Job::query()->whereIn('status', [Job::WAITING, Job::RUNNING, Job::QUEUED])->limit(200)->get() as $job) {
            if (hash_equals(Token::token((string) $job->uuid), $token)) {
                if ($job->status === Job::WAITING) {
                    $job->forceFill(['wake_at' => Carbon::now(), 'status' => Job::QUEUED])->save();
                    (new Jobs())->dispatch($job);
                }

                return true;
            }
        }

        return false;
    }
}
