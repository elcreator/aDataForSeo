<?php

namespace Elcreator\aDataForSeo\Listeners;

use Elcreator\aDataForSeo\Analysis\Research\Lexicon;
use Elcreator\aDataForSeo\Integrations\Sites;
use Elcreator\aDataForSeo\Models\Issue;
use Elcreator\aDataForSeo\Models\Project;
use Elcreator\aDataForSeo\Models\Target;
use Elcreator\aDataForSeo\Services\Actor;
use Elcreator\aDataForSeo\Services\GatewayWriter;
use Elcreator\aDataForSeo\Services\Reports;
use Elcreator\aDataForSeo\Services\Writer;
use Elcreator\aDataForSeo\Support\Keys;
use Elcreator\aDataForSeo\Support\Lang;
use Elcreator\aDataForSeo\Support\Manager;
use Throwable;

/**
 * The "Search" block under sSeo's own title and description fields (sSeo's
 * `sSeoFieldsRender`, one call per language tab): the page's query, demand, position,
 * Google's top 10, the open findings, and suggested values with "Use this".
 *
 * "Use this" only fills sSeo's field and marks the document changed; the document's own
 * Save stores it. The button also leaves the value the field had when the form opened in
 * hidden inputs, so {@see SeoFieldsJournal} can book the change and close the finding.
 * Every value is escaped; the script carries no CMS tag syntax.
 */
final class SeoFieldsHint
{
    private const FIELDS = ['meta_title', 'meta_description'];

    public static function handle($params = []): string
    {
        try {
            $params = (array) $params;
            $id = (int) ($params['resource_id'] ?? 0);
            $actor = Actor::current();

            if ($id <= 0 || ($params['resource_type'] ?? 'document') !== 'document' || !$actor->mayUse()) {
                return '';
            }

            $domain = (string) ($params['domain_key'] ?? 'default');
            $project = Project::query()->where('domain_key', $domain)->first() ?? Project::query()->where('domain_key', 'default')->first();

            return $project === null ? '' : self::render($project, $id, (string) ($params['lang'] ?? 'base'), $actor);
        } catch (Throwable) {
            return '';
        }
    }

    public static function render(Project $project, int $id, string $lang, Actor $actor): string
    {
        $data = Reports::document($project, $id);
        $suggestions = self::suggestions($project, $id, $lang);

        if ($data['targets'] === [] && $data['issues'] === [] && $suggestions === []) {
            return '';
        }

        $e = [DashboardWidget::class, 'escape'];
        $t = static fn (string $key, array $replace = []) => $e(Lang::get('aDataForSeo::global.' . $key, $replace));
        $ai = self::aiSubject($project, $id, $lang, $actor);
        $attrs = ' data-adfs-lang="' . $e($lang) . '"';

        if ($ai !== null) {
            $attrs .= ' data-url="' . $e(Manager::moduleUrl()) . '" data-token="' . $e(self::csrfToken()) . '"'
                . ' data-t-price="' . $t('ui_writer_price', ['count' => GatewayWriter::VARIANTS, 'price' => ':price']) . '"'
                . ' data-t-writing="' . $t('ui_writer_writing') . '" data-t-use="' . $t('ui_writer_use') . '"'
                . ' data-t-note="' . $t('ui_writer_note') . '" data-t-cost="' . $t('ui_writer_cost', ['cost' => ':cost']) . '"'
                . ' data-t-none="' . $t('ui_writer_none') . '" data-t-language="' . $t('ui_writer_language') . '" data-t-error="' . $t('error_network') . '"';
        }

        $html = '<div class="adfs-seo" style="margin:.5rem 0 1rem;padding:.75rem 1rem;border:1px solid rgba(0,0,0,.12);border-radius:4px"' . $attrs . '>'
            . '<div style="font-weight:600;margin-bottom:.25rem">' . $t('panel_title') . '</div>';

        foreach ($data['targets'] as $target) {
            if (!self::inLanguage((string) $target['lang'], $lang)) {
                continue;
            }

            $html .= '<div><b>' . $e((string) ($target['primary_keyword'] ?? '')) . '</b>'
                . ($target['demand_real'] !== null ? ' · ' . $t('panel_demand', ['n' => (int) $target['demand_real']]) : '')
                . ($target['tier'] ? ' · ' . $e((string) $target['tier']) : '') . '</div>';
            $results = array_slice((array) ($target['serp']['results'] ?? []), 0, 10);

            if ($results !== []) {
                $html .= '<details style="font-size:12px"><summary>' . $t('panel_top10') . '</summary>';

                foreach ($results as $row) {
                    $html .= '<div>' . (int) $row['position'] . '. ' . $e((string) ($row['host'] ?? '—')) . ' — ' . $e((string) ($row['title'] ?? '')) . '</div>';
                }

                $html .= '</details>';
            }
        }

        foreach (array_slice($data['positions'], 0, 3) as $position) {
            $html .= '<div style="font-size:12px">' . $e($position['date'] . ' · ' . $position['keyword'] . ': ' . ($position['position'] ?? '—')) . '</div>';
        }

        if ($data['issues'] !== []) {
            $html .= '<ul style="margin:.25rem 0 .5rem 1.25rem;font-size:12px">';

            foreach ($data['issues'] as $issue) {
                $html .= '<li>' . $t('code_' . $issue['code']) . '</li>';
            }

            $html .= '</ul>';
        }

        foreach ($suggestions as $s) {
            $html .= '<div style="display:flex;gap:.5rem;align-items:baseline;margin:.25rem 0;font-size:13px">'
                . '<span style="color:#666;min-width:6rem">' . $t('field_' . $s['field']) . '</span>'
                . '<span style="flex:1">' . $e($s['value']) . '</span>'
                . '<button type="button" class="btn btn-sm btn-secondary" data-adfs-use data-field="' . $e($s['field']) . '" data-value="' . $e($s['value']) . '"'
                . ' data-issue="' . (int) $s['issue'] . '" data-target="' . (int) $s['target'] . '">' . $t('ui_writer_use') . '</button></div>';
        }

        if ($ai !== null) {
            $html .= '<div style="margin-top:.5rem"><button type="button" class="btn btn-sm btn-secondary" data-adfs-ai'
                . ' data-issue="' . (int) ($ai['issue'] ?? 0) . '" data-target="' . (int) ($ai['target'] ?? 0) . '">' . $t('ui_writer_suggest') . '</button>'
                . '<div data-adfs-ai-out style="font-size:13px"></div></div>';
        }

        return $html . '<p style="font-size:12px;color:#666;margin:.5rem 0 0">' . $t('panel_hint_fields') . '</p><div data-adfs-journal hidden></div></div>' . self::script();
    }

    /**
     * Values worth one click, for this language tab: what open findings suggest for the title or
     * description, then the target's template title and description.
     *
     * @return array<int, array{field: string, value: string, issue: ?int, target: ?int}>
     */
    public static function suggestions(Project $project, int $id, string $lang): array
    {
        $out = [];
        $seen = [];
        $add = static function (string $field, string $value, ?int $issue, ?int $target) use (&$out, &$seen) {
            $value = trim($value);

            if ($value === '' || isset($seen[$field . "\0" . $value])) {
                return;
            }

            $seen[$field . "\0" . $value] = true;
            $out[] = ['field' => $field, 'value' => $value, 'issue' => $issue, 'target' => $target];
        };

        $issues = Issue::query()->where('project_id', $project->getKey())->where('document_id', $id)->where('status', Issue::OPEN)->where('fix', 'seo_fields')->get();

        foreach ($issues as $issue) {
            if (!self::inLanguage((string) $issue->detail('lang', 'base'), $lang)) {
                continue;
            }

            foreach (self::FIELDS as $field) {
                $add($field, (string) $issue->detail('suggest.' . $field, ''), (int) $issue->getKey(), null);
            }
        }

        $lexicon = new Lexicon($project->setting('vertical'));

        foreach (Target::query()->where('project_id', $project->getKey())->where('document_id', $id)->orderByDesc('score')->get() as $target) {
            if (!self::inLanguage((string) $target->lang, $lang)) {
                continue;
            }

            $add('meta_title', Writer::targetTitle($target, $lexicon, (string) $target->geo), null, (int) $target->getKey());
            $add('meta_description', Writer::targetDescription($target, $lexicon, (string) $target->geo), null, (int) $target->getKey());
        }

        return $out;
    }

    /** A tab of one sLang language shows that language only; `base` (no sLang) shows everything. */
    private static function inLanguage(string $itemLang, string $tabLang): bool
    {
        return $tabLang === 'base' || !Sites::hasSLang() || $itemLang === 'base' || $itemLang === '' || $itemLang === $tabLang;
    }

    /** What "Suggest with AI" writes for: the page's best target in this tab, else a title/description finding. */
    private static function aiSubject(Project $project, int $id, string $lang, Actor $actor): ?array
    {
        if (!GatewayWriter::enabled($project) || !$actor->maySpend() || !$actor->principal()->mayEditDocument($id) || Keys::gatewayFor($actor->principal()) === null) {
            return null;
        }

        foreach (Target::query()->where('project_id', $project->getKey())->where('document_id', $id)->orderByDesc('score')->get() as $target) {
            if (self::inLanguage((string) $target->lang, $lang)) {
                return ['target' => (int) $target->getKey()];
            }
        }

        foreach (self::suggestions($project, $id, $lang) as $s) {
            if ($s['issue'] !== null) {
                return ['issue' => $s['issue']];
            }
        }

        return null;
    }

    /** The token the manager's CSRF check compares with: the session's own. */
    private static function csrfToken(): string
    {
        if (isset($_SESSION['_token']) && is_string($_SESSION['_token'])) {
            return $_SESSION['_token'];
        }

        try {
            return (string) csrf_token();
        } catch (Throwable) {
            return '';
        }
    }

    /** Once per page (every language tab renders a block); delegated clicks, text set as text only. */
    private static function script(): string
    {
        return <<<'HTML'
<script>
(function () {
    if (window.adfsSeoFields) { return; }
    window.adfsSeoFields = true;
    function field(box, name) { return document.querySelector('[name="sseo[' + box.getAttribute('data-adfs-lang') + '][' + name + ']"]'); }
    function hidden(box, name, value) {
        var journal = box.querySelector('[data-adfs-journal]');
        var el = journal.querySelector('[name="' + name + '"]');
        if (!el) { el = document.createElement('input'); el.type = 'hidden'; el.name = name; journal.appendChild(el); }
        el.value = value;
    }
    function use(box, name, value, issue, target) {
        var el = field(box, name);
        if (!el || !value) { return; }
        var key = 'adfs_seo[' + box.getAttribute('data-adfs-lang') + '][' + name + ']';
        hidden(box, key + '[before]', el.defaultValue);
        hidden(box, key + '[issue]', issue || '');
        hidden(box, key + '[target]', target || '');
        el.value = value;
        window.documentDirty = true;
    }
    function el(tag, text, css) { var n = document.createElement(tag); if (text) { n.textContent = text; } if (css) { n.style.cssText = css; } return n; }
    function post(box, path, body) {
        return fetch(box.getAttribute('data-url') + path, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': box.getAttribute('data-token') }, body: JSON.stringify(body) })
            .then(function (r) { return r.json(); })
            .catch(function () { return { ok: false, message: box.getAttribute('data-t-error') }; });
    }
    function euro(n) { return '€' + Number(n || 0).toFixed(4); }
    function ai(button) {
        var box = button.closest('.adfs-seo');
        var out = box.querySelector('[data-adfs-ai-out]');
        var issue = Number(button.getAttribute('data-issue')) || 0;
        var target = Number(button.getAttribute('data-target')) || 0;
        var subject = target ? { target: target } : { issue: issue };
        button.disabled = true;
        if (!button.getAttribute('data-quoted')) {
            post(box, '/writer/quote', subject).then(function (r) {
                button.disabled = false;
                if (!r || r.ok === false) { out.textContent = (r && r.message) || box.getAttribute('data-t-error'); return; }
                var e = r.estimate || {};
                var price = e.unknown ? '?' : (e.high && e.high > e.low ? euro(e.low) + ' – ' + euro(e.high) : euro(e.amount));
                button.setAttribute('data-quoted', '1');
                button.textContent = box.getAttribute('data-t-price').replace(':price', price);
            });
            return;
        }
        button.textContent = box.getAttribute('data-t-writing');
        post(box, '/writer/meta', Object.assign({ confirm: true }, subject)).then(function (r) {
            button.disabled = false;
            button.removeAttribute('data-quoted');
            button.textContent = button.getAttribute('data-label');
            out.textContent = '';
            if (!r || r.ok === false) { out.textContent = (r && r.message) || box.getAttribute('data-t-error'); return; }
            out.appendChild(el('p', box.getAttribute('data-t-note') + ' ' + box.getAttribute('data-t-cost').replace(':cost', euro(r.cost)), 'color:#666;margin:.25rem 0'));
            (r.warnings || []).forEach(function (w) { out.appendChild(el('p', w.code === 'language_not_evaluated' ? box.getAttribute('data-t-language') : w.message, 'color:#8a6d3b;margin:.25rem 0')); });
            if (!(r.variants || []).length) { out.appendChild(el('p', box.getAttribute('data-t-none'))); }
            (r.variants || []).forEach(function (v) {
                var row = el('div', '', 'border:1px solid rgba(0,0,0,.12);border-radius:4px;padding:.25rem .5rem;margin:.25rem 0');
                row.appendChild(el('div', v.title, 'font-weight:600'));
                if (v.description) { row.appendChild(el('div', v.description)); }
                var b = el('button', box.getAttribute('data-t-use'));
                b.type = 'button';
                b.className = 'btn btn-sm btn-secondary';
                b.addEventListener('click', function () {
                    use(box, 'meta_title', v.title, issue, target);
                    use(box, 'meta_description', v.description, issue, target);
                });
                row.appendChild(b);
                out.appendChild(row);
            });
        });
    }
    document.addEventListener('click', function (event) {
        var button = event.target.closest && event.target.closest('[data-adfs-use],[data-adfs-ai]');
        if (!button) { return; }
        var box = button.closest('.adfs-seo');
        if (button.hasAttribute('data-adfs-ai')) {
            if (!button.getAttribute('data-label')) { button.setAttribute('data-label', button.textContent); }
            ai(button);
            return;
        }
        use(box, button.getAttribute('data-field'), button.getAttribute('data-value'), button.getAttribute('data-issue'), button.getAttribute('data-target'));
    });
})();
</script>
HTML;
    }
}
