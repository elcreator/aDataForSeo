<?php

namespace Elcreator\aDataForSeo\Engine\Steps;

use Elcreator\aDataForSeo\Engine\Outcome;
use Elcreator\aDataForSeo\Engine\Runtime;
use Elcreator\aDataForSeo\Models\Step;

/**
 * One kind of step. A runner must be safe to call twice for the same step:
 * whatever it paid for is written to the step before it returns.
 */
abstract class StepRunner
{
    abstract public function run(Step $step, Runtime $rt): Outcome;

    public static function for(string $kind): self
    {
        return match ($kind) {
            'post' => new Post(),
            'wait' => new Wait(),
            'fetch' => new Fetch(),
            'live' => new Live(),
            'analyse' => new Analyse(),
            'gw_submit' => new GatewaySubmit(),
            'gw_wait' => new GatewayWait(),
            default => throw new \InvalidArgumentException('Unknown step kind ' . $kind),
        };
    }

    /** @return array<int, string> stored response paths */
    protected static function storedResponses(Step $step): array
    {
        return array_values((array) (($step->output ?? [])['responses'] ?? []));
    }

    protected static function saveOutput(Step $step, array $changes): void
    {
        $step->output = array_replace((array) ($step->output ?? []), $changes);
        $step->save();
    }

    /** Replace `{id}` / `$ref` placeholders with the first DataForSEO id of the referenced post step. */
    protected static function resolveRef(mixed $value, ?string $id): mixed
    {
        if ($id === null) {
            return $value;
        }

        if (is_string($value)) {
            return str_replace(['{id}', '$ref'], $id, $value);
        }

        if (is_array($value)) {
            return array_map(static fn ($v) => self::resolveRef($v, $id), $value);
        }

        return $value;
    }

    protected static function firstId(?Step $post): ?string
    {
        $ids = array_keys((array) ($post?->dfs_task_ids ?? []));

        return $ids === [] ? null : (string) $ids[0];
    }
}
