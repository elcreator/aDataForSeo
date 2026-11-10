<?php

namespace Elcreator\aDataForSeo\Fixes;

use Elcreator\aDataForSeo\Models\Change;
use Elcreator\aDataForSeo\Models\Issue;
use Elcreator\aDataForSeo\Models\Target;
use Elcreator\aDataForSeo\Services\WorkbenchException;
use Elcreator\aDataForSeo\Support\Principal;

/**
 * One fix code. `preview()` shows exactly what `apply()` would write, with the
 * values there now; `apply()` writes it and records every value it replaced.
 * Permission is checked in both, for the manager clicking, at that moment —
 * never cached (hard rule 5).
 */
abstract class Fix
{
    abstract public function code(): string;

    /**
     * @return array<int, array{target: string, resource_id: ?int, resource_type: string, lang: string, field: string, before: ?string, after: ?string}>
     * @throws WorkbenchException
     */
    abstract public function preview(Issue|Target $subject, Principal $principal, array $input = []): array;

    /**
     * @return Change[]
     * @throws WorkbenchException
     */
    abstract public function apply(Issue|Target $subject, Principal $principal, array $input = []): array;

    /** Put back what one change replaced, while the field still holds what we wrote. */
    abstract public function undo(Change $change, Principal $principal): void;

    public static function for(string $code): self
    {
        return match ($code) {
            'seo_fields' => new SeoFields(),
            'redirect' => new Redirect(),
            'draft' => new DraftDocument(),
            'manual' => new Manual(),
            default => throw WorkbenchException::invalid('error_fix_unknown'),
        };
    }

    public static function forTarget(string $target): self
    {
        return match ($target) {
            'sseo' => new SeoFields(),
            'redirect' => new Redirect(),
            'document' => new DraftDocument(),
            default => throw WorkbenchException::invalid('error_fix_unknown'),
        };
    }
}
