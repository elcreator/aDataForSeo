<?php

namespace Elcreator\aDataForSeo\Queue;

use Elcreator\aDataForSeo\Models\Job;
use Elcreator\aDataForSeo\Support\Config;
use Elcreator\aDataForSeo\Support\Lang;
use RuntimeException;

/**
 * Chooses the queue that carries a job.
 *
 * `auto` prefers sTask when it is installed and usable (its own manager page
 * and scheduling); otherwise the CMS's system task queue, which is always
 * there. `system` and `stask` force one.
 */
final class Dispatcher
{
    /** @throws RuntimeException when the forced queue is not usable */
    public static function driver(): Queue
    {
        $system = new SystemQueue();
        $stask = new STaskQueue();

        switch (Config::queueDriver()) {
            case 'system':
                return $system;
            case 'stask':
                if (!$stask->available()) {
                    throw new RuntimeException(Lang::get('aDataForSeo::global.queue_stask_missing'));
                }

                return $stask;
            default:
                return $stask->available() ? $stask : $system;
        }
    }

    public static function dispatch(Job $job): void
    {
        static::driver()->dispatch($job);
    }

    /** @return array<string, mixed> what the page shows about the queue */
    public static function status(): array
    {
        $system = new SystemQueue();
        $stask = new STaskQueue();

        try {
            $active = static::driver()->name();
            $error = null;
        } catch (RuntimeException $e) {
            $active = null;
            $error = $e->getMessage();
        }

        $hints = [];

        if ($active === 'system' || $active === null) {
            $hints[] = ['queue' => 'system', 'message' => Lang::get('aDataForSeo::global.queue_hint_system'), 'command' => 'php core/artisan system:task-worker'];
        }

        if ($active === 'stask') {
            $hints[] = ['queue' => 'stask', 'message' => Lang::get('aDataForSeo::global.queue_hint_stask'), 'command' => 'php core/artisan stask:worker'];
        }

        $hints[] = ['queue' => 'cron', 'message' => Lang::get('aDataForSeo::global.queue_hint_cron'), 'command' => '* * * * * cd /path/to/core && php artisan schedule:run >> /dev/null 2>&1'];

        return [
            'driver' => $active,
            'configured' => Config::queueDriver(),
            'error' => $error,
            'queues' => [
                'system' => ['available' => $system->available()] + $system->health(),
                'stask' => ['available' => $stask->available()] + $stask->health(),
            ],
            'hints' => $hints,
        ];
    }
}
