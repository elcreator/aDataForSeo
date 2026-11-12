<?php

namespace Elcreator\aDataForSeo\Support;

use Elcreator\aDataForSeo\Models\Job;
use Elcreator\aDataForSeo\Models\Project;

/**
 * How a project pays: its own DataForSEO account (`own`, US dollars) or the
 * ai.artur.work balance (`gateway`, euros).
 *
 * The two never mix in one number. A job remembers the currency it was planned
 * in and keeps using the matching backend even if the project is switched while
 * it runs, and the ledger sums one currency at a time.
 */
final class Billing
{
    public const OWN = 'own';
    public const GATEWAY = 'gateway';

    public const CURRENCY_OWN = 'USD';
    public const CURRENCY_GATEWAY = 'EUR';

    /** Job kinds that need record-level DataForSEO data, which the gateway never hands out. */
    public const OWN_ONLY_KINDS = ['research', 'track', 'competitors', 'custom'];

    public static function modeOf(Project $project): string
    {
        return $project->billing === self::GATEWAY ? self::GATEWAY : self::OWN;
    }

    public static function isGateway(Project $project): bool
    {
        return self::modeOf($project) === self::GATEWAY;
    }

    public static function currencyFor(string $mode): string
    {
        return $mode === self::GATEWAY ? self::CURRENCY_GATEWAY : self::CURRENCY_OWN;
    }

    public static function currencyOf(Project $project): string
    {
        return self::currencyFor(self::modeOf($project));
    }

    public static function jobIsGateway(Job $job): bool
    {
        return (string) $job->currency === self::CURRENCY_GATEWAY;
    }

    /** Whether a job kind can run through the gateway's analytics. */
    public static function gatewayCan(string $kind): bool
    {
        return !in_array($kind, self::OWN_ONLY_KINDS, true);
    }

    public static function symbol(string $currency): string
    {
        return $currency === self::CURRENCY_GATEWAY ? '€' : '$';
    }
}
