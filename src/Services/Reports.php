<?php

namespace Elcreator\aDataForSeo\Services;

use Carbon\Carbon;
use Elcreator\aDataForSeo\Api\Ledger;
use Elcreator\aDataForSeo\Models\Call;
use Elcreator\aDataForSeo\Models\Change;
use Elcreator\aDataForSeo\Models\Issue;
use Elcreator\aDataForSeo\Models\Job;
use Elcreator\aDataForSeo\Models\Keyword;
use Elcreator\aDataForSeo\Models\Project;
use Elcreator\aDataForSeo\Models\Ranking;
use Elcreator\aDataForSeo\Models\Target;
use Elcreator\aDataForSeo\Support\Billing;

/**
 * The professional's tables: matrix, keywords, rankings, issues, ledger,
 * changes. Read-only, `adataforseo` is enough.
 */
final class Reports
{
    public const PAGE = 200;

    public static function presentTarget(Target $target): array
    {
        $keyword = $target->primary_keyword_id ? Keyword::query()->find($target->primary_keyword_id) : null;
        $real = $target->demand_real;
        $raw = $target->demand_raw;

        return [
            'id' => (int) $target->getKey(),
            'country' => $target->country,
            'geo' => $target->geo,
            'deal' => $target->deal,
            'type' => $target->type,
            'lang' => $target->lang,
            'document_id' => $target->document_id,
            'document_manual' => (bool) $target->document_manual,
            'url' => $target->url,
            'proposed_url' => $target->proposed_url,
            'primary_keyword' => $keyword?->keyword,
            'measured' => $target->measured(),
            'demand_real' => $real,
            'demand_ambiguous' => $target->demand_ambiguous,
            'demand_origin' => $target->demand_origin,
            'demand_raw' => $raw,
            // The deduplication made visible: naive sum vs what really counts.
            'ratio' => $real ? round((float) $raw / $real, 2) : null,
            'kd_serp' => $target->kd_serp,
            'difficulty' => self::difficultyLabel($target->kd_serp),
            'native_share' => $target->native_share,
            'score' => $target->score,
            'tier' => $target->tier,
            'status' => $target->status,
            'serp' => $target->serp_snapshot,
        ];
    }

    public static function difficultyLabel(?float $kd): ?string
    {
        return $kd === null ? null : ($kd < 25 ? 'easy' : ($kd < 50 ? 'medium' : 'hard'));
    }

    public static function matrix(Project $project, array $filters = []): array
    {
        $query = Target::query()->where('project_id', $project->getKey());

        foreach (['country', 'lang', 'tier', 'status', 'deal', 'type'] as $field) {
            if (!empty($filters[$field]) && is_string($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        $rows = $query->orderByRaw('CASE WHEN score IS NULL THEN 1 ELSE 0 END')->orderByDesc('score')->orderByDesc('demand_real')->limit(1000)->get();
        $real = (int) $rows->sum('demand_real');
        $raw = (int) $rows->sum('demand_raw');

        return [
            'targets' => $rows->map(static fn (Target $t) => self::presentTarget($t))->all(),
            'totals' => ['targets' => $rows->count(), 'demand_real' => $real, 'demand_raw' => $raw, 'ratio' => $real > 0 ? round($raw / $real, 2) : null],
        ];
    }

    public static function keywords(Project $project, array $filters = []): array
    {
        $query = Keyword::query()->where('project_id', $project->getKey());

        if (!empty($filters['target'])) {
            $query->where('target_id', (int) $filters['target']);
        }

        foreach (['market', 'lang'] as $field) {
            if (!empty($filters[$field]) && is_string($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (!empty($filters['q']) && is_string($filters['q'])) {
            $query->where('keyword', 'like', '%' . str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($filters['q'])) . '%');
        }

        $page = max(1, (int) ($filters['page'] ?? 1));
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('volume')->offset(($page - 1) * self::PAGE)->limit(self::PAGE)->get();

        return [
            'keywords' => $rows->map(static fn (Keyword $k) => [
                'id' => (int) $k->getKey(), 'keyword' => $k->keyword, 'market' => $k->market, 'lang' => $k->lang,
                'volume' => $k->volume, 'monthly' => $k->monthly, 'kd_labs' => $k->kd_labs, 'core_keyword' => $k->core_keyword,
                'cpc' => $k->cpc, 'intent' => $k->intent, 'source' => $k->source, 'geo' => $k->geo, 'deal' => $k->deal, 'type' => $k->type,
                'brand' => $k->brand, 'origin' => $k->origin, 'target_id' => $k->target_id, 'cluster_id' => $k->cluster_id,
                'counted' => $k->counted, 'shared' => $k->shared,
            ])->all(),
            'total' => $total,
            'page' => $page,
            'per_page' => self::PAGE,
        ];
    }

    public static function rankings(Project $project): array
    {
        $dates = Ranking::query()->where('project_id', $project->getKey())->distinct()->orderByDesc('date')->limit(12)->pluck('date')
            ->map(static fn ($d) => Carbon::parse($d)->toDateString())->all();
        $rows = [];

        foreach (Ranking::query()->where('project_id', $project->getKey())->whereIn('date', $dates ?: ['1970-01-01'])->orderBy('keyword')->get() as $ranking) {
            $date = Carbon::parse($ranking->date)->toDateString();
            $rows[$ranking->keyword]['keyword'] = $ranking->keyword;
            $rows[$ranking->keyword]['target_id'] = $ranking->target_id;
            $rows[$ranking->keyword]['positions'][$date] = $ranking->position;
            $rows[$ranking->keyword]['url'] = $ranking->url ?? ($rows[$ranking->keyword]['url'] ?? null);
            $rows[$ranking->keyword]['features'] = $ranking->serp_features;
        }

        foreach ($rows as $keyword => $row) {
            $latest = $row['positions'][$dates[0] ?? ''] ?? null;
            $previous = isset($dates[1]) ? ($row['positions'][$dates[1]] ?? null) : null;
            $rows[$keyword]['latest'] = $latest;
            $rows[$keyword]['delta'] = $latest !== null && $previous !== null ? $previous - $latest : null;
        }

        return ['dates' => array_reverse($dates), 'rows' => array_values($rows)];
    }

    public static function issues(Project $project, array $filters = []): array
    {
        $query = Issue::query()->where('project_id', $project->getKey());
        $status = is_string($filters['status'] ?? null) && $filters['status'] !== '' ? $filters['status'] : Issue::OPEN;

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        foreach (['source', 'code', 'severity'] as $field) {
            if (!empty($filters[$field]) && is_string($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        return ['issues' => $query->orderByDesc('id')->limit(1000)->get()->map(static fn (Issue $i) => [
            'id' => (int) $i->getKey(), 'source' => $i->source, 'code' => $i->code, 'severity' => $i->severity, 'url' => $i->url,
            'document_id' => $i->document_id, 'details' => $i->details, 'fix' => $i->fix, 'status' => $i->status,
            'updated_at' => $i->updated_at?->toIso8601String(),
        ])->all()];
    }

    public static function ledger(Project $project): array
    {
        $ledger = new Ledger();
        $rows = Call::query()->where('project_id', $project->getKey())->orderByDesc('id')->limit(300)->get();
        $months = [];

        foreach (range(0, 5) as $back) {
            $from = Carbon::now()->startOfMonth()->subMonths($back);
            // One currency at a time: a project that switched how it pays never sees dollars added to euros.
            $months[] = ['month' => $from->format('Y-m'), 'spent' => $ledger->spent((int) $project->getKey(), $from, $from->copy()->addMonth(), Billing::currencyOf($project))];
        }

        return [
            'calls' => $rows->map(static fn (Call $c) => [
                'id' => (int) $c->getKey(), 'endpoint' => $c->endpoint, 'env' => $c->env, 'currency' => (string) ($c->currency ?: 'USD'), 'cost' => $c->cost, 'estimate' => $c->estimate,
                'cached' => $c->cached, 'status_code' => $c->status_code, 'tasks' => $c->tasks, 'items' => $c->items,
                'job_id' => $c->job_id, 'fetched_at' => $c->fetched_at?->toIso8601String(),
            ])->all(),
            'months' => array_reverse($months),
            'currency' => Billing::currencyOf($project),
            'jobs' => Job::query()->where('project_id', $project->getKey())->where('spent', '>', 0)->orderByDesc('id')->limit(50)->get()
                ->map(static fn (Job $j) => ['uuid' => $j->uuid, 'kind' => $j->kind, 'currency' => (string) ($j->currency ?: 'USD'), 'estimate' => $j->estimate, 'spent' => $j->spent, 'status' => $j->status])->all(),
        ];
    }

    public static function changes(Project $project): array
    {
        return ['changes' => Change::query()->where('project_id', $project->getKey())->orderByDesc('id')->limit(200)->get()
            ->map(static fn (Change $c) => Fixes::presentChange($c))->all()];
    }

    /** The document panel: what the plugin knows about one document. */
    public static function document(Project $project, int $documentId): array
    {
        $targets = Target::query()->where('project_id', $project->getKey())->where('document_id', $documentId)->orderByDesc('score')->get();
        $issues = Issue::query()->where('project_id', $project->getKey())->where('document_id', $documentId)->where('status', Issue::OPEN)->get();
        $keywordIds = $targets->pluck('primary_keyword_id')->filter()->all();
        $positions = Ranking::query()->where('project_id', $project->getKey())->whereIn('keyword_id', $keywordIds ?: [0])
            ->orderByDesc('date')->limit(20)->get()->map(static fn (Ranking $r) => ['keyword' => $r->keyword, 'date' => Carbon::parse($r->date)->toDateString(), 'position' => $r->position])->all();

        return [
            'targets' => $targets->map(static fn (Target $t) => self::presentTarget($t))->all(),
            'issues' => $issues->map(static fn (Issue $i) => ['id' => (int) $i->getKey(), 'code' => $i->code, 'severity' => $i->severity, 'fix' => $i->fix, 'details' => $i->details])->all(),
            'positions' => $positions,
        ];
    }
}
