<?php

namespace Elcreator\aDataForSeo\Listeners;

use Carbon\Carbon;
use Elcreator\aDataForSeo\Integrations\Sites;
use Elcreator\aDataForSeo\Models\Change;
use Elcreator\aDataForSeo\Models\Issue;
use Elcreator\aDataForSeo\Models\Project;
use Elcreator\aDataForSeo\Models\Target;
use Elcreator\aDataForSeo\Services\Actor;
use Throwable;

/**
 * Books a value chosen with "Use this" under sSeo's fields once the document is saved
 * (`OnDocFormSave`), as the module's own Apply does: a row in `adfs_changes` with the value
 * the field held when the form opened, so it shows in the history and can be undone, and
 * the finding it answered is marked applied at once instead of at the next audit.
 *
 * sSeo stores the field from the same request; the value booked is the one posted, so the
 * order of the two listeners does not matter. Only fields a suggestion touched are booked,
 * and only by someone who may edit the document.
 */
final class SeoFieldsJournal
{
    private const FIELDS = ['meta_title', 'meta_description'];

    public static function handle($params = []): void
    {
        try {
            self::book((int) (((array) $params)['id'] ?? 0), $_POST, Actor::current());
        } catch (Throwable) {
            // A failed booking must never stop the document from saving.
        }
    }

    /** @return Change[] */
    public static function book(int $id, array $post, Actor $actor): array
    {
        $entries = $post['adfs_seo'] ?? null;

        if ($id <= 0 || !is_array($entries) || !$actor->mayUse() || !$actor->principal()->mayEditDocument($id)) {
            return [];
        }

        $changes = [];

        foreach ($entries as $lang => $fields) {
            $lang = (string) $lang;

            if (!is_array($fields) || !preg_match('/^(base|[a-z]{2}(-[a-z]{2})?)$/i', $lang)) {
                continue;
            }

            foreach (self::FIELDS as $field) {
                $entry = $fields[$field] ?? null;
                $after = $post['sseo'][$lang][$field] ?? null;

                if (!is_array($entry) || !is_scalar($after)) {
                    continue;
                }

                $before = is_scalar($entry['before'] ?? null) ? (string) $entry['before'] : '';
                $after = (string) $after;
                $issue = (int) ($entry['issue'] ?? 0) > 0 ? Issue::query()->whereKey((int) $entry['issue'])->where('document_id', $id)->first() : null;
                $target = (int) ($entry['target'] ?? 0) > 0 ? Target::query()->whereKey((int) $entry['target'])->where('document_id', $id)->first() : null;
                $projectId = $issue?->project_id ?? $target?->project_id ?? Project::query()->where('domain_key', 'default')->value('id');

                if ($after === $before || $projectId === null) {
                    continue;
                }

                $changes[] = Change::query()->create([
                    'project_id' => (int) $projectId,
                    'issue_id' => $issue?->getKey(),
                    'target_id' => $target?->getKey(),
                    'user_id' => $actor->userId(),
                    'target' => 'sseo',
                    'resource_id' => $id,
                    'resource_type' => 'document',
                    'lang' => $lang,
                    'domain_key' => Sites::domainKey($id),
                    'field' => $field,
                    'before' => $before,
                    'after' => $after,
                    'applied_at' => Carbon::now(),
                ]);

                if ($issue !== null && $issue->status === Issue::OPEN) {
                    $issue->forceFill(['status' => Issue::APPLIED])->save();
                }
            }
        }

        return $changes;
    }
}
