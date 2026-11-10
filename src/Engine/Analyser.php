<?php

namespace Elcreator\aDataForSeo\Engine;

use Elcreator\aDataForSeo\Analysis;
use Elcreator\aDataForSeo\Models\Step;

/**
 * An `analyse` step's work. Returns any of:
 *  - `output`: a summary kept on the step;
 *  - `result`: merged into the job's result (what the page shows when done);
 *  - `extend`: step definitions inserted right after this step;
 *  - `fill`: step name => tasks for a deferred step.
 */
abstract class Analyser
{
    private const MAP = [
        'sitemap' => Analysis\SitemapAnalyser::class,
        'coverage' => Analysis\IndexCoverage::class,
        'audit' => Analysis\Audit::class,
        'universe' => Analysis\Research\Universe::class,
        'targets' => Analysis\Research\Targets::class,
        'difficulty_hosts' => Analysis\Research\DifficultyHosts::class,
        'difficulty' => Analysis\Research\Difficulty::class,
        'priority' => Analysis\Research\Priority::class,
        'tracking' => Analysis\Tracking::class,
        'competitors_pick' => Analysis\CompetitorsPick::class,
        'competitors' => Analysis\Competitors::class,
        'custom' => Analysis\CustomResult::class,
    ];

    /** @return array{output?: array, result?: array, extend?: array, fill?: array} */
    abstract public function run(Runtime $rt, Step $step): array;

    public static function make(string $name): self
    {
        $class = self::MAP[$name] ?? null;

        if ($class === null) {
            throw new \InvalidArgumentException('Unknown analyser ' . $name);
        }

        return new $class();
    }

    /** @return string[] */
    public static function names(): array
    {
        return array_keys(self::MAP);
    }
}
