<?php

namespace Elcreator\aDataForSeo\Analysis;

use Elcreator\aDataForSeo\Models\Issue;

/**
 * Keeps `adfs_issues` in step with the latest analysis of one source.
 *
 * A finding is identified by (code, URL). Seen again: its details refresh and
 * a manager's "ignored" or "applied" stays. Gone: an open finding becomes
 * resolved. So re-running an audit never resurrects what a manager dismissed
 * and never keeps reporting what was fixed.
 */
final class IssueBook
{
    public const SEVERITIES = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];

    /**
     * @param array<int, array{code: string, severity?: string, url?: string, document_id?: ?int, details?: array, fix?: ?string}> $issues
     * @param string[]|null $codes the codes this run speaks for (null: every code of the source)
     * @return array{created: int, updated: int, resolved: int}
     */
    public static function sync(int $projectId, ?int $jobId, string $source, array $issues, ?array $codes = null): array
    {
        $seen = [];
        $created = $updated = 0;

        foreach ($issues as $issue) {
            $url = mb_substr((string) ($issue['url'] ?? ''), 0, 500);
            $hash = sha1(mb_strtolower($url));
            $key = $issue['code'] . '|' . $hash;

            if (isset($seen[$key])) {
                continue;
            }

            $attributes = [
                'job_id' => $jobId,
                'source' => $source,
                'severity' => isset(self::SEVERITIES[$issue['severity'] ?? '']) ? $issue['severity'] : 'medium',
                'url' => $url,
                'document_id' => $issue['document_id'] ?? null,
                'details' => (array) ($issue['details'] ?? []),
                'fix' => $issue['fix'] ?? null,
            ];

            $row = Issue::query()->where('project_id', $projectId)->where('code', $issue['code'])->where('url_hash', $hash)->first();

            if ($row === null) {
                $row = Issue::query()->create($attributes + ['project_id' => $projectId, 'code' => $issue['code'], 'url_hash' => $hash, 'status' => Issue::OPEN]);
                $created++;
            } else {
                if ($row->status === Issue::RESOLVED) {
                    $attributes['status'] = Issue::OPEN;
                }

                $row->forceFill($attributes)->save();
                $updated++;
            }

            $seen[$key] = (int) $row->getKey();
        }

        $resolved = Issue::query()
            ->where('project_id', $projectId)
            ->where('source', $source)
            ->where('status', Issue::OPEN)
            ->when($codes !== null, static fn ($q) => $q->whereIn('code', $codes))
            ->whereNotIn('id', array_values($seen) ?: [0])
            ->update(['status' => Issue::RESOLVED]);

        return ['created' => $created, 'updated' => $updated, 'resolved' => (int) $resolved];
    }

    /** @return array<string, int> open findings of a source by severity */
    public static function counts(int $projectId, ?string $source = null): array
    {
        $counts = array_fill_keys(array_keys(self::SEVERITIES), 0);

        Issue::query()->where('project_id', $projectId)->where('status', Issue::OPEN)
            ->when($source !== null, static fn ($q) => $q->where('source', $source))
            ->selectRaw('severity, COUNT(*) as n')->groupBy('severity')->get()
            ->each(static function ($row) use (&$counts) {
                $counts[(string) $row->severity] = (int) $row->n;
            });

        return $counts;
    }
}
