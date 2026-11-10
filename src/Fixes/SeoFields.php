<?php

namespace Elcreator\aDataForSeo\Fixes;

use Carbon\Carbon;
use Elcreator\aDataForSeo\Analysis\Research\Lexicon;
use Elcreator\aDataForSeo\Integrations\Sites;
use Elcreator\aDataForSeo\Integrations\SSeo;
use Elcreator\aDataForSeo\Models\Change;
use Elcreator\aDataForSeo\Models\Issue;
use Elcreator\aDataForSeo\Models\Target;
use Elcreator\aDataForSeo\Services\WorkbenchException;
use Elcreator\aDataForSeo\Services\Writer;
use Elcreator\aDataForSeo\Support\Principal;

/**
 * Title, description, robots, canonical, sitemap exclusion — written through
 * sSeo, changed fields only. Needs `save_document` and access to the document,
 * as the CMS's own save does. Without sSeo, nothing is written.
 */
final class SeoFields extends Fix
{
    private const MAX = ['meta_title' => 255, 'meta_description' => 1000, 'meta_keywords' => 255, 'robots' => 64, 'canonical_url' => 500, 'exclude_from_sitemap' => 1];

    public function code(): string
    {
        return 'seo_fields';
    }

    public function preview(Issue|Target $subject, Principal $principal, array $input = []): array
    {
        [$id, $lang, $fields] = $this->resolve($subject, $input);
        $this->authorize($id, $principal);

        $before = SSeo::available() ? SSeo::read($id, 'document', $lang, array_keys($fields)) : array_fill_keys(array_keys($fields), null);
        $rows = [];

        foreach ($fields as $field => $after) {
            if ((string) ($before[$field] ?? '') === (string) $after) {
                continue;
            }

            $rows[] = ['target' => 'sseo', 'resource_id' => $id, 'resource_type' => 'document', 'lang' => $lang, 'field' => $field, 'before' => $before[$field] ?? null, 'after' => (string) $after];
        }

        return $rows;
    }

    public function apply(Issue|Target $subject, Principal $principal, array $input = []): array
    {
        if (!SSeo::available()) {
            throw WorkbenchException::refused('error_sseo_missing');
        }

        $rows = $this->preview($subject, $principal, $input);

        if ($rows === []) {
            return [];
        }

        $id = (int) $rows[0]['resource_id'];
        $lang = (string) $rows[0]['lang'];
        $write = [];

        foreach ($rows as $row) {
            $write[$row['field']] = $row['field'] === 'exclude_from_sitemap' ? (int) $row['after'] : $row['after'];
        }

        SSeo::write($id, 'document', $lang, $write);

        if (isset($write['exclude_from_sitemap'])) {
            SSeo::regenerateSitemap($id);
        }

        $domainKey = Sites::domainKey($id);
        $changes = [];

        foreach ($rows as $row) {
            $changes[] = Change::query()->create([
                'project_id' => $subject->project_id,
                'issue_id' => $subject instanceof Issue ? $subject->getKey() : null,
                'target_id' => $subject instanceof Target ? $subject->getKey() : null,
                'user_id' => $principal->id,
                'target' => 'sseo',
                'resource_id' => $id,
                'resource_type' => 'document',
                'lang' => $lang,
                'domain_key' => $domainKey,
                'field' => $row['field'],
                'before' => $row['before'],
                'after' => $row['after'],
                'applied_at' => Carbon::now(),
            ]);
        }

        return $changes;
    }

    public function undo(Change $change, Principal $principal): void
    {
        $this->authorize((int) $change->resource_id, $principal);

        if (!SSeo::available()) {
            throw WorkbenchException::refused('error_sseo_missing');
        }

        $now = (string) (SSeo::read((int) $change->resource_id, (string) $change->resource_type, (string) $change->lang, [$change->field])[$change->field] ?? '');

        if ($now !== (string) $change->after) {
            throw WorkbenchException::refused('error_undo_changed', ['field' => $change->field], ['change' => $change->getKey()]);
        }

        SSeo::write((int) $change->resource_id, (string) $change->resource_type, (string) $change->lang, [$change->field => (string) $change->before]);
    }

    /** @return array{0: int, 1: string, 2: array<string, string>} */
    private function resolve(Issue|Target $subject, array $input): array
    {
        $id = (int) ($subject->document_id ?? 0);

        if ($id <= 0) {
            throw WorkbenchException::invalid('error_fix_no_document');
        }

        if ($subject instanceof Issue) {
            $lang = (string) ($subject->detail('lang') ?: Sites::defaultLanguage());
            $fields = (array) $subject->detail('suggest', []);
        } else {
            $lexicon = new Lexicon();
            $geo = (string) $subject->geo;
            $lang = Sites::hasSLang() && in_array($subject->lang, Sites::languages(), true) ? (string) $subject->lang : Sites::defaultLanguage();
            $fields = ['meta_title' => Writer::targetTitle($subject, $lexicon, $geo), 'meta_description' => Writer::targetDescription($subject, $lexicon, $geo)];
        }

        if (!Sites::hasSLang()) {
            $lang = 'base';
        }

        foreach ((array) ($input['fields'] ?? []) as $field => $value) {
            if (isset(self::MAX[$field]) && is_scalar($value)) {
                $fields[$field] = (string) $value;
            }
        }

        $clean = [];

        foreach ($fields as $field => $value) {
            if (!isset(self::MAX[$field])) {
                continue;
            }

            $value = trim(strip_tags((string) $value));

            if ($field === 'canonical_url' && $value !== '' && !preg_match('#^https?://#i', $value)) {
                continue;
            }

            if ($field === 'robots' && !preg_match('/^[a-z, -]*$/i', $value)) {
                continue;
            }

            $clean[$field] = mb_substr($value, 0, self::MAX[$field]);
        }

        if ($clean === []) {
            throw WorkbenchException::invalid('error_fix_nothing');
        }

        return [$id, $lang, $clean];
    }

    private function authorize(int $id, Principal $principal): void
    {
        if (!$principal->mayEditDocument($id)) {
            throw WorkbenchException::forbidden('seo fields of document ' . $id . ' by user ' . $principal->id);
        }
    }
}
