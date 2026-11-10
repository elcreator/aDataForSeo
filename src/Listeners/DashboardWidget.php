<?php

namespace Elcreator\aDataForSeo\Listeners;

use Elcreator\aDataForSeo\Models\Issue;
use Elcreator\aDataForSeo\Models\Job;
use Elcreator\aDataForSeo\Models\Project;
use Elcreator\aDataForSeo\Models\Ranking;
use Elcreator\aDataForSeo\Models\Target;
use Elcreator\aDataForSeo\Services\Actor;
use Elcreator\aDataForSeo\Services\Jobs;
use Elcreator\aDataForSeo\Services\Projects;
use Elcreator\aDataForSeo\Support\Lang;
use Elcreator\aDataForSeo\Support\Manager;
use Throwable;

/**
 * The welcome-page widget: index coverage, open urgent findings, position
 * changes, spend this month. Every value goes through `escape_html_and_tags()`
 * because the CMS parses `[[...]]`, `{{...}}` and `[(...)]` in widget bodies.
 */
final class DashboardWidget
{
    public static function handle($params = [])
    {
        $widgets = is_array($params['widgets'] ?? null) ? $params['widgets'] : [];

        try {
            $actor = Actor::current();

            if (!$actor->mayUse()) {
                return null;
            }

            $project = Project::query()->where('domain_key', 'default')->first();
            $widgets['adataforseo'] = [
                'menuindex' => '15',
                'id' => 'adataforseo',
                'cols' => 'col-sm-6',
                'icon' => Manager::icon('search', 'search'),
                'title' => self::escape(Lang::get('aDataForSeo::global.widget_title')),
                'body' => self::body($project),
                'hide' => '0',
            ];
        } catch (Throwable) {
            return null;
        }

        return serialize($widgets);
    }

    public static function body(?Project $project): string
    {
        if ($project === null) {
            return '<div style="padding:1rem">' . self::escape(Lang::get('aDataForSeo::global.widget_empty')) . '</div>';
        }

        $job = Job::query()->where('project_id', $project->getKey())->where('kind', 'coverage')->where('status', Job::DONE)
            ->orderByDesc('finished_at')->first();
        $coverage = (array) (((array) ($job?->result ?? []))['coverage'] ?? []);
        $urgent = Issue::query()->where('project_id', $project->getKey())->where('status', Issue::OPEN)->whereIn('severity', ['critical', 'high'])->count();
        $p1 = Target::query()->where('project_id', $project->getKey())->where('tier', 'P1')->whereNull('document_id')->count();
        $latest = Ranking::query()->where('project_id', $project->getKey())->max('date');
        $top10 = $latest === null ? null : Ranking::query()->where('project_id', $project->getKey())->where('date', $latest)->whereNotNull('position')->where('position', '<=', 10)->count();
        $spent = Projects::present($project)['budget']['spent_month'];

        $rows = [
            [Lang::get('aDataForSeo::global.widget_coverage'), isset($coverage['sitemap']) ? $coverage['indexed_in_sitemap'] . ' / ' . $coverage['sitemap'] : '—'],
            [Lang::get('aDataForSeo::global.widget_urgent'), (string) $urgent],
            [Lang::get('aDataForSeo::global.widget_p1'), (string) $p1],
            [Lang::get('aDataForSeo::global.widget_top10'), $top10 === null ? '—' : (string) $top10],
            [Lang::get('aDataForSeo::global.widget_spent'), Jobs::money($spent)],
        ];

        $html = '<table class="table table-sm" style="margin:0">';

        foreach ($rows as [$label, $value]) {
            $html .= '<tr><td>' . self::escape($label) . '</td><td style="text-align:right"><b>' . self::escape($value) . '</b></td></tr>';
        }

        return $html . '</table>';
    }

    public static function escape(string $text): string
    {
        return function_exists('escape_html_and_tags')
            ? (string) escape_html_and_tags($text)
            : str_replace(['[', ']', '{', '}'], ['&#91;', '&#93;', '&#123;', '&#125;'], htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
    }
}
