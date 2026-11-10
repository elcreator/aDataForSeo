<?php

namespace Elcreator\aDataForSeo\Engine;

use Carbon\Carbon;

/**
 * What running one step came to: done, wait until a moment, call again now
 * (the slice ran out of time mid-step), or fail.
 */
final class Outcome
{
    public const DONE = 'done';
    public const WAITING = 'waiting';
    public const PARTIAL = 'partial';
    public const FAILED = 'failed';
    public const SKIPPED = 'skipped';

    private function __construct(
        public readonly string $status,
        public readonly ?Carbon $nextCheckAt = null,
        public readonly ?string $error = null
    ) {
    }

    public static function done(): self
    {
        return new self(self::DONE);
    }

    public static function skipped(): self
    {
        return new self(self::SKIPPED);
    }

    public static function waitUntil(Carbon $at): self
    {
        return new self(self::WAITING, $at);
    }

    public static function partial(): self
    {
        return new self(self::PARTIAL);
    }

    public static function failed(string $error): self
    {
        return new self(self::FAILED, null, $error);
    }
}
