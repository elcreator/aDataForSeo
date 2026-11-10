<?php

namespace Elcreator\aDataForSeo\Engine;

/**
 * How a slice ended. The caller queues a successor only for `more`: a job
 * that is `waiting` is woken by `adfs:tick` or the pingback at `wakeAt`.
 */
final class SliceResult
{
    public const DONE = 'done';
    public const MORE = 'more';
    public const WAITING = 'waiting';
    public const FAILED = 'failed';
    public const IDLE = 'idle';

    public function __construct(
        public readonly string $status,
        public readonly string $message = '',
        public readonly ?\DateTimeInterface $wakeAt = null
    ) {
    }
}
