<?php

namespace Elcreator\aDataForSeo\Listeners;

use Elcreator\aDataForSeo\Models\Project;
use Elcreator\aDataForSeo\Services\Actor;
use Elcreator\aDataForSeo\Services\Reports;
use Elcreator\aDataForSeo\Support\Lang;
use Throwable;

/**
 * A "Search" block on the document form: the target query, its demand,
 * position, the SERP top 10 and the open findings, with a link to the module
 * where the suggested title and description are applied to sSeo.
 * Read-only here; every value is escaped.
 */
final class DocumentPanel
{
    public static function handle($params = [])
    {
        try {
            $id = (int) ($params['id'] ?? 0);

            if ($id <= 0 || !Actor::current()->mayUse()) {
                return '';
            }

            $project = Project::query()->where('domain_key', 'default')->first();

            return $project === null ? '' : self::render(Reports::document($project, $id));
        } catch (Throwable) {
            return '';
        }
    }

    public static function render(array $data): string
    {
        if ($data['targets'] === [] && $data['issues'] === []) {
            return '';
        }

        $e = [DashboardWidget::class, 'escape'];
        $html = '<div class="tab-section" id="adataforseo-panel"><div class="tab-header">' . $e(Lang::get('aDataForSeo::global.panel_title')) . '</div><div class="tab-body" style="padding:1rem">';

        foreach ($data['targets'] as $target) {
            $html .= '<p><b>' . $e((string) ($target['primary_keyword'] ?? '')) . '</b> — '
                . $e(Lang::get('aDataForSeo::global.panel_demand', ['n' => (int) ($target['demand_real'] ?? 0)]))
                . ($target['tier'] ? ' · ' . $e((string) $target['tier']) : '') . '</p>';

            foreach (array_slice((array) ($target['serp']['results'] ?? []), 0, 10) as $row) {
                $html .= '<div style="font-size:12px">' . (int) $row['position'] . '. ' . $e((string) ($row['host'] ?? '—')) . ' — ' . $e((string) $row['title']) . '</div>';
            }
        }

        foreach ($data['positions'] as $position) {
            $html .= '<div style="font-size:12px">' . $e($position['date'] . ' · ' . $position['keyword'] . ': ' . ($position['position'] ?? '—')) . '</div>';
        }

        if ($data['issues'] !== []) {
            $html .= '<ul>';

            foreach ($data['issues'] as $issue) {
                $html .= '<li>' . $e(Lang::get('aDataForSeo::global.code_' . $issue['code'])) . '</li>';
            }

            $html .= '</ul>';
        }

        return $html . '<p style="font-size:12px;color:#666">' . $e(Lang::get('aDataForSeo::global.panel_hint')) . '</p></div></div>';
    }
}
