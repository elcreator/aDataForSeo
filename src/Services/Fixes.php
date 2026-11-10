<?php

namespace Elcreator\aDataForSeo\Services;

use Carbon\Carbon;
use Elcreator\aDataForSeo\Fixes\Fix;
use Elcreator\aDataForSeo\Fixes\Manual;
use Elcreator\aDataForSeo\Integrations\SSeo;
use Elcreator\aDataForSeo\Models\Change;
use Elcreator\aDataForSeo\Models\Issue;
use Elcreator\aDataForSeo\Models\Project;
use Elcreator\aDataForSeo\Models\Target;

/**
 * The buttons on the cards: preview, apply, ignore, undo. Nothing changes on
 * the site without a manager's click (hard rule 8), and every value replaced
 * is kept in `adfs_changes`.
 */
final class Fixes
{
    public function __construct(private readonly Actor $actor)
    {
    }

    public function issue(Project $project, int $id): Issue
    {
        $issue = Issue::query()->where('project_id', $project->getKey())->find($id);

        if ($issue === null) {
            throw WorkbenchException::notFound(\Elcreator\aDataForSeo\Support\Lang::get('aDataForSeo::global.what_issue'));
        }

        return $issue;
    }

    public function target(Project $project, int $id): Target
    {
        $target = Target::query()->where('project_id', $project->getKey())->find($id);

        if ($target === null) {
            throw WorkbenchException::notFound(\Elcreator\aDataForSeo\Support\Lang::get('aDataForSeo::global.what_target'));
        }

        return $target;
    }

    public function previewIssue(Issue $issue, array $input = []): array
    {
        $this->actor->authorize();
        $code = (string) ($issue->fix ?: 'manual');
        $fix = Fix::for($code);

        return [
            'fix' => $code,
            'available' => $code === 'manual' || SSeo::available() || $code === 'draft',
            'instructions' => Manual::instructions((string) $issue->code),
            'changes' => $fix->preview($issue, $this->actor->principal(), $input),
        ];
    }

    /** @return Change[] */
    public function applyIssue(Issue $issue, array $input = []): array
    {
        $this->actor->authorize();
        $code = (string) ($issue->fix ?: 'manual');
        $changes = Fix::for($code)->apply($issue, $this->actor->principal(), $input);
        $issue->forceFill(['status' => $code === 'manual' ? Issue::RESOLVED : Issue::APPLIED])->save();

        return $changes;
    }

    public function ignore(Issue $issue, bool $ignore = true): Issue
    {
        $this->actor->authorize();
        $issue->forceFill(['status' => $ignore ? Issue::IGNORED : Issue::OPEN])->save();

        return $issue;
    }

    /** `kind`: draft (missing page) or seo_fields (suggested title/description for its document). */
    public function previewTarget(Target $target, string $kind, array $input = []): array
    {
        $this->actor->authorize();

        return ['fix' => $kind, 'changes' => Fix::for($kind)->preview($target, $this->actor->principal(), $input)];
    }

    /** @return Change[] */
    public function applyTarget(Target $target, string $kind, array $input = []): array
    {
        $this->actor->authorize();

        if (!in_array($kind, ['draft', 'seo_fields'], true)) {
            throw WorkbenchException::invalid('error_fix_unknown');
        }

        return Fix::for($kind)->apply($target, $this->actor->principal(), $input);
    }

    public function undo(Project $project, int $changeId): Change
    {
        $this->actor->authorize();
        $change = Change::query()->where('project_id', $project->getKey())->whereNull('undone_at')->find($changeId);

        if ($change === null) {
            throw WorkbenchException::notFound(\Elcreator\aDataForSeo\Support\Lang::get('aDataForSeo::global.what_change'));
        }

        Fix::forTarget((string) $change->target)->undo($change, $this->actor->principal());
        $change->forceFill(['undone_at' => Carbon::now()])->save();

        if ($change->issue_id !== null && !Change::query()->where('issue_id', $change->issue_id)->whereNull('undone_at')->exists()) {
            Issue::query()->whereKey($change->issue_id)->update(['status' => Issue::OPEN]);
        }

        return $change;
    }

    public static function presentChange(Change $change): array
    {
        return [
            'id' => (int) $change->getKey(),
            'target' => $change->target,
            'resource_id' => $change->resource_id,
            'lang' => $change->lang,
            'field' => $change->field,
            'before' => $change->before,
            'after' => $change->after,
            'user_id' => $change->user_id,
            'issue_id' => $change->issue_id,
            'target_id' => $change->target_id,
            'applied_at' => $change->applied_at?->toIso8601String(),
            'undone_at' => $change->undone_at?->toIso8601String(),
        ];
    }
}
