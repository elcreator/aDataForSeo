<?php

namespace Elcreator\aDataForSeo\Fixes;

use Carbon\Carbon;
use Elcreator\aDataForSeo\Integrations\SSeo;
use Elcreator\aDataForSeo\Models\Change;
use Elcreator\aDataForSeo\Models\Issue;
use Elcreator\aDataForSeo\Models\Target;
use Elcreator\aDataForSeo\Services\WorkbenchException;
use Elcreator\aDataForSeo\Support\Config;
use Elcreator\aDataForSeo\Support\Principal;

/**
 * A dead URL → a 301 to the suggested page, through sSeo's redirect table
 * (`old_url` without the leading slash, as sSeo stores it). Needs the
 * permission that opens sSeo's module (`fixes.redirect_permission`).
 */
final class Redirect extends Fix
{
    public function code(): string
    {
        return 'redirect';
    }

    public function preview(Issue|Target $subject, Principal $principal, array $input = []): array
    {
        $this->authorize($principal);
        [$old, $new] = $this->urls($subject, $input);

        return [['target' => 'redirect', 'resource_id' => null, 'resource_type' => 'redirect', 'lang' => 'base', 'field' => $old, 'before' => null, 'after' => $new]];
    }

    public function apply(Issue|Target $subject, Principal $principal, array $input = []): array
    {
        if (!SSeo::available()) {
            throw WorkbenchException::refused('error_sseo_missing');
        }

        $this->authorize($principal);
        [$old, $new] = $this->urls($subject, $input);

        if (SSeo::redirectExists($old, 'all') || SSeo::redirectExists($old, 'default')) {
            throw WorkbenchException::refused('error_redirect_exists', ['url' => $old]);
        }

        $id = SSeo::redirect($old, $new, 301, 'all');

        return [Change::query()->create([
            'project_id' => $subject->project_id,
            'issue_id' => $subject instanceof Issue ? $subject->getKey() : null,
            'user_id' => $principal->id,
            'target' => 'redirect',
            'resource_id' => $id,
            'resource_type' => 'redirect',
            'field' => $old,
            'before' => null,
            'after' => $new,
            'applied_at' => Carbon::now(),
        ])];
    }

    public function undo(Change $change, Principal $principal): void
    {
        $this->authorize($principal);

        if (!SSeo::deleteRedirect((int) $change->resource_id, (string) $change->field, (string) $change->after)) {
            throw WorkbenchException::refused('error_undo_changed', ['field' => $change->field], ['change' => $change->getKey()]);
        }
    }

    /** @return array{0: string, 1: string} old path (no leading slash) and new URL */
    private function urls(Issue|Target $subject, array $input): array
    {
        $old = ltrim((string) parse_url((string) ($subject->url ?? ''), PHP_URL_PATH), '/');
        $query = (string) parse_url((string) ($subject->url ?? ''), PHP_URL_QUERY);
        $old .= $query !== '' ? '?' . $query : '';
        $new = trim((string) ($input['new_url'] ?? ($subject instanceof Issue ? $subject->detail('suggest.new_url', '') : '')));

        if ($old === '') {
            throw WorkbenchException::invalid('error_redirect_root');
        }

        if ($new === '' || (!preg_match('#^https?://#i', $new) && !str_starts_with($new, '/')) || preg_match('/[\s<>"]/', $new)) {
            throw WorkbenchException::fields(['new_url' => \Elcreator\aDataForSeo\Support\Lang::get('aDataForSeo::global.error_redirect_target')]);
        }

        if (ltrim((string) parse_url($new, PHP_URL_PATH), '/') === $old) {
            throw WorkbenchException::fields(['new_url' => \Elcreator\aDataForSeo\Support\Lang::get('aDataForSeo::global.error_redirect_loop')]);
        }

        return [mb_substr($old, 0, 500), mb_substr($new, 0, 500)];
    }

    private function authorize(Principal $principal): void
    {
        $permission = (string) Config::get('fixes.redirect_permission', 'exec_module');

        if (!$principal->isAdministrator() && !$principal->can($permission)) {
            throw WorkbenchException::forbidden('redirect by user ' . $principal->id);
        }
    }
}
