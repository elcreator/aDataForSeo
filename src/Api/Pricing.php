<?php

namespace Elcreator\aDataForSeo\Api;

use Elcreator\aDataForSeo\Support\Config;
use Throwable;

/**
 * The price table, as DataForSEO publishes it in `appendix/user_data` →
 * `result[0].price` (free): a tree by endpoint path whose leaves are
 * `priority_<low|normal|high>` lists of `{cost_type: per_request|per_result, cost}`.
 *
 * Kept in a file for `api.pricing_ttl`; when a refresh fails the stale table is
 * used rather than none (a price a day old beats "unknown" for every call).
 * Nothing here is hardcoded: an endpoint missing from the table is unpriced.
 */
final class Pricing
{
    private static ?array $override = null;

    private ?array $table = null;

    /** @param \Closure(): ?array $loader fetches a fresh `price` tree, or null */
    public function __construct(private readonly ?\Closure $loader = null)
    {
    }

    /** Pin the table (tests, the offline demo); null goes back to the file and the loader. */
    public static function useTable(?array $table): void
    {
        self::$override = $table;
    }

    public static function file(): string
    {
        return Config::storagePath('pricing.json');
    }

    /** Remember a table read elsewhere (Keys::verify reads user_data anyway). */
    public static function store(array $price): void
    {
        if ($price === []) {
            return;
        }

        $file = self::file();

        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0775, true);
        }

        @file_put_contents($file, json_encode(['fetched_at' => time(), 'price' => $price]), LOCK_EX);
    }

    public function table(): ?array
    {
        if (self::$override !== null) {
            return self::$override;
        }

        if ($this->table !== null) {
            return $this->table;
        }

        $stored = $this->readFile();
        $fresh = $stored !== null && (time() - (int) ($stored['fetched_at'] ?? 0)) < (int) Config::get('api.pricing_ttl', 86400);

        if ($fresh) {
            return $this->table = (array) $stored['price'];
        }

        if ($this->loader !== null) {
            try {
                $price = ($this->loader)();

                if (is_array($price) && $price !== []) {
                    self::store($price);

                    return $this->table = $price;
                }
            } catch (Throwable) {
                // Stale-on-failure below.
            }
        }

        return $this->table = $stored !== null ? (array) $stored['price'] : null;
    }

    /**
     * Price entries for one endpoint and priority, or null when unpriced.
     *
     * `serp/google/organic/live/advanced` is looked up as deep as the tree
     * goes (`live/advanced`, else `live`), so a table that prices a function
     * as a whole still answers for its variants.
     *
     * @return array<int, array{cost_type: string, cost: float}>|null
     */
    public function for(string $endpoint, int $priority = 1): ?array
    {
        $table = $this->table();

        if ($table === null) {
            return null;
        }

        $node = $table;
        $depth = 0;

        foreach (explode('/', Endpoints::normalise($endpoint)) as $segment) {
            if (!is_array($node) || !isset($node[$segment]) || !is_array($node[$segment])) {
                break;
            }

            $node = $node[$segment];
            $depth++;

            if (self::entries($node) !== null || self::hasPriorities($node)) {
                break;
            }
        }

        if ($depth < 2) {
            return null;
        }

        if (self::hasPriorities($node)) {
            $wanted = $priority >= 2 ? 'priority_high' : 'priority_normal';
            $candidate = $node[$wanted] ?? $node['priority_normal'] ?? null;

            if ($candidate === null) {
                foreach ($node as $key => $value) {
                    if (str_starts_with((string) $key, 'priority_')) {
                        $candidate = $value;
                        break;
                    }
                }
            }

            return is_array($candidate) ? self::entries($candidate) : null;
        }

        return self::entries($node);
    }

    private static function hasPriorities(array $node): bool
    {
        foreach (array_keys($node) as $key) {
            if (str_starts_with((string) $key, 'priority_')) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, array{cost_type: string, cost: float}>|null */
    private static function entries(array $node): ?array
    {
        $list = isset($node['cost_type']) ? [$node] : $node;

        if (!array_is_list($list) || $list === []) {
            return null;
        }

        $entries = [];

        foreach ($list as $entry) {
            if (!is_array($entry) || !isset($entry['cost_type'], $entry['cost']) || !is_numeric($entry['cost'])) {
                return null;
            }

            $entries[] = ['cost_type' => (string) $entry['cost_type'], 'cost' => (float) $entry['cost']];
        }

        return $entries;
    }

    private function readFile(): ?array
    {
        $file = self::file();

        if (!is_file($file)) {
            return null;
        }

        $data = json_decode((string) @file_get_contents($file), true);

        return is_array($data) && isset($data['price']) && is_array($data['price']) ? $data : null;
    }
}
