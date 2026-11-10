<?php

namespace Elcreator\aDataForSeo\Analysis;

use Elcreator\aDataForSeo\Analysis\Research\Targets;
use Elcreator\aDataForSeo\Api\Response;
use Elcreator\aDataForSeo\Engine\Analyser;
use Elcreator\aDataForSeo\Engine\Runtime;
use Elcreator\aDataForSeo\Models\Step;
use Elcreator\aDataForSeo\Support\UrlMap;

/**
 * "Pages they have that you don't": competitors' pages with search traffic
 * whose address words appear in none of this site's addresses.
 */
final class Competitors extends Analyser
{
    public const CARDS = 15;

    public function run(Runtime $rt, Step $step): array
    {
        $project = $rt->project;
        $ours = array_map(static fn ($e) => self::words((string) parse_url($e['url'], PHP_URL_PATH)), UrlMap::for($project)->urls());
        $pages = [];

        foreach (Runtime::okTasks($rt->responses('relevant_pages')) as $task) {
            $domain = (string) ($task['data']['target'] ?? '');

            foreach (Response::itemsOf($task) as $item) {
                $address = (string) ($item['page_address'] ?? '');
                $path = (string) parse_url($address, PHP_URL_PATH);
                $words = self::words($path);

                if ($words === [] || self::covered($words, $ours)) {
                    continue;
                }

                $pages[] = [
                    'code' => 'competitor_page',
                    'severity' => 'low',
                    'url' => $address,
                    'fix' => 'manual',
                    'details' => [
                        'competitor' => $domain,
                        'etv' => (float) ($item['metrics']['organic']['etv'] ?? 0),
                        'keywords' => (int) ($item['metrics']['organic']['count'] ?? 0),
                        'words' => $words,
                    ],
                ];
            }
        }

        usort($pages, static fn ($a, $b) => $b['details']['etv'] <=> $a['details']['etv']);
        $pages = array_slice($pages, 0, self::CARDS);

        foreach ($pages as $i => $page) {
            $pages[$i]['severity'] = $i < 3 ? 'medium' : 'low';
        }

        $counts = IssueBook::sync((int) $project->getKey(), (int) $rt->job->getKey(), 'competitors', $pages);

        return ['output' => ['cards' => count($pages)] + $counts, 'result' => ['gaps' => count($pages)]];
    }

    /** @return string[] meaningful words of a path */
    public static function words(string $path): array
    {
        $words = array_filter(explode('-', str_replace('/', '-', Targets::slugify(rawurldecode($path)))), static fn ($w) => mb_strlen($w) >= 3 && !ctype_digit($w));

        return array_values(array_unique($words));
    }

    private static function covered(array $words, array $ours): bool
    {
        foreach ($ours as $our) {
            if ($our !== [] && count(array_intersect($words, $our)) >= min(2, count($words))) {
                return true;
            }
        }

        return false;
    }
}
