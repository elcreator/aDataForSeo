<?php

namespace Elcreator\aDataForSeo\Engine\Plans;

use Elcreator\aDataForSeo\Api\Context;
use Elcreator\aDataForSeo\Models\Job;
use Elcreator\aDataForSeo\Models\Keyword;
use Elcreator\aDataForSeo\Models\Project;
use Elcreator\aDataForSeo\Services\WorkbenchException;

/**
 * Difficulty through the gateway: the gateway scores up to 100 keywords of one market and
 * language per call, from the live top 10, and answers with a score and counts, never the
 * ranking sites.
 *
 * Keywords come from the targets that already exist (made earlier with a DataForSEO account
 * of the site's own) and from the manager: the job option `keywords` is a list of searches
 * to score. This plan does not need the research job, which the gateway cannot run.
 */
final class GatewayDifficultyPlan extends Plan
{
    /** What the gateway accepts in one call. */
    public const CHUNK = 100;

    /** What a manager may enter in one job. */
    public const MAX_KEYWORDS = 500;

    public function build(Job $job, Project $project, $client, Context $context): array
    {
        /** @var array<string, array{country: string, language: string, keywords: array<string, true>}> $groups */
        $groups = [];
        $add = static function (string $country, string $language, string $keyword) use (&$groups): void {
            $keyword = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $keyword) ?? ''));

            if ($keyword === '' || mb_strlen($keyword) > 700) {
                return;
            }

            $key = $country . '|' . $language;
            $groups[$key] ??= ['country' => $country, 'language' => $language, 'keywords' => []];
            $groups[$key]['keywords'][$keyword] = true;
        };

        foreach (DifficultyPlan::targets($project, (bool) $job->option('recheck', false), false) as $target) {
            $keyword = Keyword::query()->find($target->primary_keyword_id);

            if ($project->market((string) $target->country) !== null && $keyword !== null) {
                $add(strtoupper((string) $target->country), (string) $target->lang, (string) $keyword->keyword);
            }
        }

        $market = $project->primaryMarket();
        $entered = array_slice(array_values(array_filter((array) $job->option('keywords', []), 'is_scalar')), 0, self::MAX_KEYWORDS);

        if ($market !== null && !empty($market['country']) && !empty($market['languages'])) {
            foreach ($entered as $keyword) {
                $add(strtoupper((string) $market['country']), (string) $market['languages'][0], (string) $keyword);
            }
        }

        if ($groups === []) {
            throw WorkbenchException::invalid('error_gateway_no_targets');
        }

        $submits = $waits = $names = [];
        $n = 0;

        foreach ($groups as $group) {
            foreach (array_chunk(array_keys($group['keywords']), self::CHUNK) as $chunk) {
                $name = 'diff' . (++$n);
                [$submit, $wait] = self::gateway($name, 'keywords/difficulty', ['keywords' => $chunk, 'location' => $group['country'], 'language' => $group['language']]);
                $submits[] = $submit;
                $waits[] = $wait;
                $names[] = $name . '_wait';
            }
        }

        // Every call goes out before the first wait, so the gateway works on them side by side.
        return array_merge($submits, $waits, [
            ['name' => 'difficulty', 'kind' => 'analyse', 'payload' => ['analyser' => 'gw_difficulty', 'waits' => $names]],
            ['name' => 'priority', 'kind' => 'analyse', 'payload' => ['analyser' => 'priority']],
        ]);
    }
}
