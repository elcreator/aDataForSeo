<?php

namespace Elcreator\aDataForSeo\Mcp;

use Elcreator\aDataForSeo\Models\Job;
use Elcreator\aDataForSeo\Services\Actor;
use Elcreator\aDataForSeo\Services\Cards;
use Elcreator\aDataForSeo\Services\Fixes;
use Elcreator\aDataForSeo\Services\Jobs;
use Elcreator\aDataForSeo\Services\Projects;
use Elcreator\aDataForSeo\Services\Reports;
use Elcreator\aDataForSeo\Services\WorkbenchException;

/**
 * What the MCP tools do, without the protocol, so it is testable without
 * eMCP. Every operation is one service call for the token owner: an agent
 * gets exactly what that manager gets on the page, permissions included.
 */
final class Operations
{
    public static function coverage(Actor $actor, array $args = []): array
    {
        $actor->authorize();
        $project = Projects::current();
        $job = Job::query()->where('project_id', $project->getKey())->where('kind', 'coverage')->where('status', Job::DONE)->orderByDesc('finished_at')->first();

        return [
            'coverage' => ((array) ($job?->result ?? []))['coverage'] ?? null,
            'not_indexed' => Reports::issues($project, ['code' => 'not_indexed'])['issues'],
        ];
    }

    public static function audit(Actor $actor, array $args = []): array
    {
        $actor->authorize();

        return ['cards' => Cards::for(Projects::current())];
    }

    public static function matrix(Actor $actor, array $args = []): array
    {
        $actor->authorize();

        return Reports::matrix(Projects::current(), array_intersect_key($args, array_flip(['country', 'lang', 'tier', 'status'])));
    }

    public static function rankings(Actor $actor, array $args = []): array
    {
        $actor->authorize();

        return Reports::rankings(Projects::current());
    }

    public static function serp(Actor $actor, array $args = []): array
    {
        $actor->authorize();
        $project = Projects::current();
        $target = (new Fixes($actor))->target($project, (int) ($args['target'] ?? 0));

        return ['target' => Reports::presentTarget($target)];
    }

    public static function estimate(Actor $actor, array $args = []): array
    {
        $result = (new Jobs())->estimate((string) ($args['kind'] ?? ''), Projects::current(), (array) ($args['options'] ?? []), $actor);

        return ['estimate' => $result['estimate']->toArray(), 'steps' => $result['steps']];
    }

    /** Mutating: the same Fixes service and the same permission checks as the page. */
    public static function applyFix(Actor $actor, array $args = []): array
    {
        $actor->authorize();
        $fixes = new Fixes($actor);
        $issue = $fixes->issue(Projects::current(), (int) ($args['issue'] ?? 0));
        $input = array_intersect_key($args, array_flip(['fields', 'new_url']));

        if (!empty($args['preview'])) {
            return $fixes->previewIssue($issue, $input);
        }

        return ['changes' => array_map([Fixes::class, 'presentChange'], $fixes->applyIssue($issue, $input))];
    }

    public static function createDraft(Actor $actor, array $args = []): array
    {
        $actor->authorize();
        $fixes = new Fixes($actor);
        $target = $fixes->target(Projects::current(), (int) ($args['target'] ?? 0));
        $input = array_intersect_key($args, array_flip(['parent', 'pagetitle']));

        if (!empty($args['preview'])) {
            return $fixes->previewTarget($target, 'draft', $input);
        }

        return [
            'changes' => array_map([Fixes::class, 'presentChange'], $fixes->applyTarget($target, 'draft', $input)),
            'target' => Reports::presentTarget($target->refresh()),
        ];
    }

    public static function startJob(Actor $actor, array $args = []): array
    {
        $actor->authorize('spend');
        $job = (new Jobs())->start((string) ($args['kind'] ?? ''), Projects::current(), (array) ($args['options'] ?? []), $actor, 'mcp');

        return ['job' => Jobs::present($job)];
    }

    public static function undoChange(Actor $actor, array $args = []): array
    {
        $actor->authorize();
        $change = (new Fixes($actor))->undo(Projects::current(), (int) ($args['change'] ?? 0));

        return ['change' => Fixes::presentChange($change)];
    }

    public static function explain(WorkbenchException $e): string
    {
        return $e->getMessage();
    }
}
