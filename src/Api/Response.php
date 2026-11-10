<?php

namespace Elcreator\aDataForSeo\Api;

/**
 * One DataForSEO answer.
 *
 * The HTTP status can be 200 while a task failed, so every task carries its
 * own `status_code`: 20000 ok, 20100 task created; anything else is a per-task
 * failure reported here, not an exception.
 */
final class Response
{
    public const OK = 20000;
    public const CREATED = 20100;

    public function __construct(
        private readonly array $data,
        public readonly bool $cached = false,
        public readonly ?int $callId = null,
        public readonly float $recordedCost = 0.0
    ) {
    }

    public function raw(): array
    {
        return $this->data;
    }

    public function statusCode(): int
    {
        return (int) ($this->data['status_code'] ?? 0);
    }

    /** What DataForSEO says it charged for this answer (see Ledger for task_get). */
    public function cost(): float
    {
        return (float) ($this->data['cost'] ?? 0);
    }

    /** @return array<int, array<string, mixed>> */
    public function tasks(): array
    {
        return array_values(array_filter((array) ($this->data['tasks'] ?? []), 'is_array'));
    }

    public function task(int $index = 0): ?array
    {
        return $this->tasks()[$index] ?? null;
    }

    public static function taskOk(?array $task): bool
    {
        return $task !== null && in_array((int) ($task['status_code'] ?? 0), [self::OK, self::CREATED], true);
    }

    public function ok(int $index = 0): bool
    {
        return self::taskOk($this->task($index));
    }

    /** @return array<int, array<string, mixed>> the task's `result` list */
    public function results(int $index = 0): array
    {
        return array_values(array_filter((array) ($this->task($index)['result'] ?? []), 'is_array'));
    }

    public function result(int $index = 0): ?array
    {
        return $this->results($index)[0] ?? null;
    }

    /**
     * The records of a task. Labs, SERP and OnPage nest them in
     * `result[0].items`; Google Ads answers with the records as `result` itself.
     *
     * @return array<int, array<string, mixed>>
     */
    public function items(int $index = 0): array
    {
        return self::itemsOf($this->task($index));
    }

    /** @return array<int, array<string, mixed>> */
    public static function itemsOf(?array $task): array
    {
        $results = array_values(array_filter((array) ($task['result'] ?? []), 'is_array'));

        if ($results === []) {
            return [];
        }

        if (array_key_exists('items', $results[0])) {
            $items = [];

            foreach ($results as $result) {
                foreach ((array) ($result['items'] ?? []) as $item) {
                    if (is_array($item)) {
                        $items[] = $item;
                    }
                }
            }

            return $items;
        }

        return $results;
    }

    /** Total records across tasks, for the ledger's history of item counts. */
    public function itemCount(): int
    {
        $count = 0;

        foreach ($this->tasks() as $task) {
            $count += count(self::itemsOf($task));
        }

        return $count;
    }
}
