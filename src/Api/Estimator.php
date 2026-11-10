<?php

namespace Elcreator\aDataForSeo\Api;

/**
 * Prices a call before it is made.
 *
 * per_request × tasks + per_result × expected items, from {@see Pricing}.
 * When the item count cannot be known in advance (`ranked_keywords` without a
 * limit, SERP `depth` above 10) the answer is a range calibrated from the
 * ledger's history for that endpoint and depth, or null when there is none.
 * Unknown is never zero.
 */
final class Estimator
{
    public function __construct(private readonly Pricing $pricing, private readonly Ledger $ledger)
    {
    }

    /**
     * @param array<int, array<string, mixed>>|null $tasks
     * @param int|null $expectItems item count the caller knows better (deferred steps)
     */
    public function estimate(string $endpoint, ?array $tasks, int $priority = 1, ?int $expectItems = null): Estimate
    {
        if (Endpoints::isFree($endpoint)) {
            return Estimate::free();
        }

        $entries = $this->pricing->for($endpoint, $priority);

        if ($entries === null) {
            return Estimate::unknown('unpriced');
        }

        $tasks = array_values(array_filter((array) $tasks, 'is_array'));
        $taskCount = max(1, count($tasks));
        $perRequest = $perResult = 0.0;

        foreach ($entries as $entry) {
            if ($entry['cost_type'] === 'per_result') {
                $perResult += $entry['cost'];
            } else {
                $perRequest += $entry['cost'];
            }
        }

        $depth = self::maxDepth($tasks);

        // Deeper SERPs are billed by pages the table does not spell out.
        if (Endpoints::family($endpoint) === 'serp' && $depth !== null && $depth > 10) {
            return $this->fromHistory($endpoint, $depth, $taskCount, 'depth');
        }

        if ($perResult <= 0) {
            return Estimate::exact($perRequest * $taskCount);
        }

        $items = $expectItems ?? self::expectedItems($endpoint, $tasks);

        if ($items === null) {
            $history = $this->fromHistory($endpoint, null, $taskCount, 'items');

            return $history;
        }

        return Estimate::exact($perRequest * $taskCount + $perResult * $items);
    }

    /** The largest `depth` among the tasks, if any asks for one. */
    public static function maxDepth(array $tasks): ?int
    {
        $depth = null;

        foreach ($tasks as $task) {
            if (isset($task['depth']) && is_numeric($task['depth'])) {
                $depth = max((int) $depth, (int) $task['depth']);
            }
        }

        return $depth;
    }

    /**
     * Items a request will be billed for, when that is knowable: a `limit`, the
     * pages of a crawl, the targets of a bulk call. Null when only the answer
     * will tell.
     */
    public static function expectedItems(string $endpoint, array $tasks): ?int
    {
        $total = 0;

        foreach ($tasks as $task) {
            if (isset($task['max_crawl_pages'])) {
                $total += max(1, (int) $task['max_crawl_pages']);
            } elseif (isset($task['limit'])) {
                $total += max(1, (int) $task['limit']);
            } elseif (isset($task['targets']) && is_array($task['targets'])) {
                $total += count($task['targets']);
            } elseif (isset($task['keywords']) && is_array($task['keywords']) && str_contains($endpoint, 'dataforseo_labs')) {
                $total += count($task['keywords']);
            } else {
                return null;
            }
        }

        return $tasks === [] ? null : $total;
    }

    private function fromHistory(string $endpoint, ?int $depth, int $taskCount, string $why): Estimate
    {
        $history = $this->ledger->history($endpoint, $depth);

        if ($history === null) {
            return Estimate::unknown($why);
        }

        return Estimate::range($history['avg'] * $taskCount, $history['min'] * $taskCount, $history['max'] * $taskCount, 'history');
    }
}
