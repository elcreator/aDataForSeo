<?php

namespace Elcreator\aDataForSeo\Fixes;

use Elcreator\aDataForSeo\Models\Change;
use Elcreator\aDataForSeo\Models\Issue;
use Elcreator\aDataForSeo\Models\Target;
use Elcreator\aDataForSeo\Services\WorkbenchException;
use Elcreator\aDataForSeo\Support\Lang;
use Elcreator\aDataForSeo\Support\Principal;

/**
 * What the plugin cannot do itself (server configuration, links inside
 * templates, Search Console): plain instructions, and "mark as done".
 */
final class Manual extends Fix
{
    public function code(): string
    {
        return 'manual';
    }

    public function preview(Issue|Target $subject, Principal $principal, array $input = []): array
    {
        $code = $subject instanceof Issue ? (string) $subject->code : 'target';

        return [['target' => 'manual', 'resource_id' => $subject->document_id ?? null, 'resource_type' => 'document', 'lang' => 'base', 'field' => 'instructions', 'before' => null, 'after' => self::instructions($code)]];
    }

    public static function instructions(string $code): string
    {
        $text = Lang::get('aDataForSeo::global.manual_' . $code);

        return str_starts_with($text, 'aDataForSeo::') ? Lang::get('aDataForSeo::global.manual_generic') : $text;
    }

    public function apply(Issue|Target $subject, Principal $principal, array $input = []): array
    {
        return [];
    }

    public function undo(Change $change, Principal $principal): void
    {
        throw WorkbenchException::invalid('error_fix_unknown');
    }
}
