<?php

namespace Elcreator\aDataForSeo\Console;

use Elcreator\aDataForSeo\Services\Jobs;
use Illuminate\Console\Command;

/**
 * The once-a-minute heartbeat: wakes jobs whose DataForSEO tasks should be
 * ready, re-queues jobs whose worker died, starts scheduled jobs, purges old
 * files and reconciles the ledger daily. Scheduled by the provider.
 */
class TickCommand extends Command
{
    protected $signature = 'adfs:tick';

    protected $description = 'Wake waiting aDataForSeo jobs, re-queue abandoned ones, start scheduled jobs and purge old data.';

    public function handle(): int
    {
        $result = (new Jobs())->tick();

        $this->info(sprintf(
            '[adfs:tick] woken %d, re-queued %d, scheduled %d, purged %d.',
            $result['woken'],
            $result['reaped'],
            $result['scheduled'],
            $result['purged']
        ));

        return self::SUCCESS;
    }
}
