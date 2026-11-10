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
use Elcreator\aDataForSeo\Support\UrlMap;
use Illuminate\Support\Facades\DB;

/**
 * A missing target page → a new **unpublished** document under the parent the
 * manager picks: alias from the proposed URL, titles from the target, sSeo
 * fields filled when sSeo is there. Never published automatically; undone
 * only while nobody has edited it.
 */
final class DraftDocument extends Fix
{
    public function code(): string
    {
        return 'draft';
    }

    public function preview(Issue|Target $subject, Principal $principal, array $input = []): array
    {
        $draft = $this->draft($subject, $principal, $input);

        return [
            ['target' => 'document', 'resource_id' => null, 'resource_type' => 'document', 'lang' => (string) $subject->lang, 'field' => 'pagetitle', 'before' => null, 'after' => $draft['pagetitle']],
            ['target' => 'document', 'resource_id' => null, 'resource_type' => 'document', 'lang' => (string) $subject->lang, 'field' => 'alias', 'before' => null, 'after' => $draft['alias']],
            ['target' => 'document', 'resource_id' => null, 'resource_type' => 'document', 'lang' => (string) $subject->lang, 'field' => 'parent', 'before' => null, 'after' => (string) $draft['parent']],
        ];
    }

    public function apply(Issue|Target $subject, Principal $principal, array $input = []): array
    {
        $draft = $this->draft($subject, $principal, $input);
        $now = time();

        $id = (int) DB::table('site_content')->insertGetId([
            'type' => 'document',
            'contentType' => 'text/html',
            'pagetitle' => $draft['pagetitle'],
            'longtitle' => $draft['pagetitle'],
            'description' => $draft['description'],
            'alias' => $draft['alias'],
            'published' => 0,
            'parent' => $draft['parent'],
            'isfolder' => 0,
            'content' => '',
            'richtext' => 1,
            'template' => $draft['template'],
            'menuindex' => 0,
            'searchable' => 1,
            'cacheable' => 1,
            'createdby' => $principal->id,
            'createdon' => $now,
            'editedby' => $principal->id,
            'editedon' => $now,
            'deleted' => 0,
            'hidemenu' => 0,
        ]);

        if (SSeo::available()) {
            SSeo::write($id, 'document', Sites::hasSLang() ? (string) $subject->lang : 'base', array_filter([
                'meta_title' => $draft['pagetitle'],
                'meta_description' => $draft['description'],
            ]));
        }

        if ($subject instanceof Target) {
            $subject->forceFill(['document_id' => $id, 'document_manual' => true])->save();
        }

        UrlMap::invalidate();

        return [Change::query()->create([
            'project_id' => $subject->project_id,
            'target_id' => $subject instanceof Target ? $subject->getKey() : null,
            'issue_id' => $subject instanceof Issue ? $subject->getKey() : null,
            'user_id' => $principal->id,
            'target' => 'document',
            'resource_id' => $id,
            'resource_type' => 'document',
            'lang' => (string) $subject->lang,
            'field' => 'create',
            'before' => null,
            'after' => $draft['alias'],
            'applied_at' => Carbon::now(),
        ])];
    }

    public function undo(Change $change, Principal $principal): void
    {
        $id = (int) $change->resource_id;
        $row = DB::table('site_content')->where('id', $id)->first(['published', 'createdon', 'editedon', 'parent']);

        if ($row === null) {
            return;
        }

        if (!$principal->mayEditDocument($id) || !$principal->can('delete_document')) {
            throw WorkbenchException::forbidden('delete draft ' . $id . ' by user ' . $principal->id);
        }

        if ((int) $row->published !== 0 || (int) $row->editedon !== (int) $row->createdon) {
            throw WorkbenchException::refused('error_undo_draft_edited', [], ['document' => $id]);
        }

        DB::table('site_content')->where('id', $id)->delete();

        if ($change->target_id !== null) {
            Target::query()->whereKey($change->target_id)->update(['document_id' => null, 'document_manual' => false]);
        }

        UrlMap::invalidate();
    }

    /** @return array{pagetitle: string, description: string, alias: string, parent: int, template: int} */
    private function draft(Issue|Target $subject, Principal $principal, array $input): array
    {
        if (!$subject instanceof Target) {
            throw WorkbenchException::invalid('error_fix_unknown');
        }

        if ($subject->document_id !== null) {
            throw WorkbenchException::refused('error_draft_exists', ['id' => $subject->document_id]);
        }

        $parent = max(0, (int) ($input['parent'] ?? 0));

        if ($parent > 0 && !DB::table('site_content')->where('id', $parent)->where('deleted', 0)->exists()) {
            throw WorkbenchException::fields(['parent' => \Elcreator\aDataForSeo\Support\Lang::get('aDataForSeo::global.error_parent')]);
        }

        if (!$principal->mayCreateDocumentUnder($parent)) {
            throw WorkbenchException::forbidden('draft under ' . $parent . ' by user ' . $principal->id);
        }

        $lexicon = new Lexicon();
        $segments = array_values(array_filter(explode('/', trim((string) parse_url((string) $subject->proposed_url, PHP_URL_PATH), '/'))));
        $alias = (string) preg_replace('/[^a-z0-9-]+/', '-', strtolower((string) (end($segments) ?: 'page')));
        $template = $parent > 0 ? (int) DB::table('site_content')->where('id', $parent)->value('template') : 0;

        return [
            'pagetitle' => mb_substr(trim((string) ($input['pagetitle'] ?? '')) ?: Writer::targetTitle($subject, $lexicon, (string) $subject->geo), 0, 255),
            'description' => Writer::targetDescription($subject, $lexicon, (string) $subject->geo),
            'alias' => trim($alias, '-') ?: 'page',
            'parent' => $parent,
            'template' => $template ?: (int) evo()->getConfig('default_template', 0),
        ];
    }
}
