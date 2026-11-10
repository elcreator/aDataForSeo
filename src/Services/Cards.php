<?php

namespace Elcreator\aDataForSeo\Services;

use Elcreator\aDataForSeo\Analysis\IssueBook;
use Elcreator\aDataForSeo\Models\Issue;
use Elcreator\aDataForSeo\Models\Job;
use Elcreator\aDataForSeo\Models\Keyword;
use Elcreator\aDataForSeo\Models\Project;
use Elcreator\aDataForSeo\Models\Target;
use Elcreator\aDataForSeo\Support\Lang;

/**
 * The home page: one card per finding, sorted by expected effect — what is
 * wrong, why it matters, how hard the fix is, and the button. Plain words,
 * no difficulty numbers; empty states say what will appear and when.
 */
final class Cards
{
    /** Effort of the fix behind each finding, for the beginner's easy/medium/hard. */
    private const EFFORT = [
        'seo_fields' => 'easy', 'redirect' => 'easy', 'draft' => 'medium', 'manual' => 'hard',
    ];

    private const SAMPLE = 8;

    /** @return array<int, array<string, mixed>> */
    public static function for(Project $project): array
    {
        $cards = [];
        $weights = IssueBook::SEVERITIES;

        $groups = Issue::query()->where('project_id', $project->getKey())->where('status', Issue::OPEN)
            ->selectRaw('code, severity, fix, COUNT(*) as n')->groupBy('code', 'severity', 'fix')->get();

        foreach ($groups as $group) {
            $items = Issue::query()->where('project_id', $project->getKey())->where('status', Issue::OPEN)
                ->where('code', $group->code)->where('severity', $group->severity)
                ->orderBy('id')->limit(self::SAMPLE)->get();
            $fix = (string) ($group->fix ?: 'manual');
            $n = (int) $group->n;

            $cards[] = [
                'type' => 'issue',
                'code' => (string) $group->code,
                'severity' => (string) $group->severity,
                'count' => $n,
                'fix' => $fix,
                'effort' => self::EFFORT[$fix] ?? 'hard',
                'title' => Lang::get('aDataForSeo::global.card_' . $group->code . '_title', ['n' => $n]),
                'why' => Lang::get('aDataForSeo::global.card_' . $group->code . '_why'),
                'effect' => ($weights[(string) $group->severity] ?? 1) * 1000 + min(999, $n * 10),
                'items' => $items->map(static fn (Issue $i) => [
                    'id' => (int) $i->getKey(),
                    'url' => $i->url,
                    'document_id' => $i->document_id,
                    'fix' => $i->fix,
                    'details' => $i->details,
                ])->all(),
            ];
        }

        foreach (Target::query()->where('project_id', $project->getKey())->where('tier', 'P1')
            ->whereIn('status', [Target::MISSING, Target::NEEDS_LOCALE])->whereNull('document_id')
            ->orderByDesc('score')->limit(10)->get() as $target) {
            $keyword = $target->primary_keyword_id ? Keyword::query()->find($target->primary_keyword_id) : null;

            $cards[] = [
                'type' => 'target',
                'code' => $target->status === Target::NEEDS_LOCALE ? 'needs_locale' : 'missing_page',
                'severity' => 'high',
                'count' => 1,
                'fix' => 'draft',
                'effort' => $target->status === Target::NEEDS_LOCALE ? 'hard' : 'medium',
                // Searches that ask for this page, explicit or not (a "houses atlantis" without "for sale" still counts).
                'title' => Lang::get('aDataForSeo::global.card_missing_page_title', ['keyword' => (string) ($keyword?->keyword ?? $target->proposed_url), 'demand' => (int) $target->demand_real + (int) $target->demand_ambiguous]),
                'why' => Lang::get('aDataForSeo::global.card_' . ($target->status === Target::NEEDS_LOCALE ? 'needs_locale' : 'missing_page') . '_why'),
                'effect' => 2500 + min(1499, (int) round((float) $target->score / 10)),
                'target' => Reports::presentTarget($target),
            ];
        }

        usort($cards, static fn ($a, $b) => $b['effect'] <=> $a['effect']);

        return $cards;
    }

    /** What the empty home page says: nothing run yet, or running, or all clear. */
    public static function state(Project $project): array
    {
        $jobs = Job::query()->where('project_id', $project->getKey());

        return [
            'ever_ran' => (clone $jobs)->where('status', Job::DONE)->exists(),
            'running' => (clone $jobs)->whereIn('status', array_merge(Job::ACTIVE, [Job::AWAITING_APPROVAL]))->count(),
            'last_done' => (clone $jobs)->where('status', Job::DONE)->orderByDesc('finished_at')->value('finished_at'),
            'kinds_done' => (clone $jobs)->where('status', Job::DONE)->distinct()->pluck('kind')->all(),
        ];
    }
}
