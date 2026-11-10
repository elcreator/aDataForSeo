<?php

namespace Elcreator\aDataForSeo\Analysis\Research;

use Elcreator\aDataForSeo\Engine\Analyser;
use Elcreator\aDataForSeo\Engine\Runtime;
use Elcreator\aDataForSeo\Models\Issue;
use Elcreator\aDataForSeo\Models\Keyword;
use Elcreator\aDataForSeo\Models\Project;
use Elcreator\aDataForSeo\Models\Step;
use Elcreator\aDataForSeo\Models\Target;
use Elcreator\aDataForSeo\Support\UrlMap;

/**
 * The page matrix: one target per (country, place, deal, type, language).
 *
 * Every rule here exists because the naive version was wrong on a real site:
 *  - variants are clustered and counted once ({@see Clusters});
 *  - a uk and an ru query with the same volume and vector are one bucket
 *    ("купити/купить квартиру варшава"): counted on the page whose locale the
 *    site has, shown as shared on the other;
 *  - a query without a deal ("квартира варшава") goes to the page people most
 *    likely want and counts as ambiguous demand, weighted later.
 */
final class Targets extends Analyser
{
    public function run(Runtime $rt, Step $step): array
    {
        $summary = self::rebuild($rt->project);

        return ['output' => $summary, 'result' => ['matrix' => $summary]];
    }

    /** @return array{targets: int, keywords: int, demand_real: int, demand_raw: int, ratio: ?float} */
    public static function rebuild(Project $project): array
    {
        $lexicon = new Lexicon($project->setting('vertical'));
        $classifier = new Classifier($lexicon, $project->brandTerms());
        $siteLanguages = $project->siteLanguages();
        $groups = [];

        $keywords = Keyword::query()->where('project_id', $project->getKey())->where('origin', false)->get();

        foreach ($keywords as $keyword) {
            $market = $project->market((string) $keyword->market);

            if ($market === null) {
                continue;
            }

            $class = $classifier->classify((string) $keyword->keyword, $market, (string) $keyword->lang);
            $keyword->forceFill(['geo' => $class['geo'], 'deal' => $class['deal'], 'type' => $class['type'], 'brand' => $class['brand'],
                'target_id' => null, 'cluster_id' => null, 'counted' => false, 'shared' => false]);

            if ($class['brand'] || ($class['deal'] === '' && $class['type'] === 'any')) {
                $keyword->save();

                continue;
            }

            $ambiguous = $class['deal'] === '';
            $deal = $ambiguous ? $lexicon->ambiguousDefault($class['type']) : $class['deal'];
            $key = implode('|', [$keyword->market, $class['geo'], $deal, $class['type'], $keyword->lang]);
            $groups[$key][] = ['model' => $keyword, 'ambiguous' => $ambiguous];
        }

        // Cluster inside each page.
        $clusters = new Clusters();
        $buckets = [];

        foreach ($groups as $key => $members) {
            $lang = explode('|', $key)[4];
            $representatives = $clusters->build(array_map(static fn ($m) => [
                'id' => (int) $m['model']->getKey(),
                'keyword' => (string) $m['model']->keyword,
                'volume' => $m['model']->volume,
                'monthly' => $m['model']->monthly,
                'core_keyword' => $m['model']->core_keyword,
            ], $members), $lexicon->stop($lang));

            foreach ($members as $member) {
                $model = $member['model'];
                $head = $representatives[(int) $model->getKey()];
                $model->cluster_id = $head;
                $model->counted = $head === (int) $model->getKey();

                $bucket = $model->counted ? Clusters::bucket(['volume' => $model->volume, 'monthly' => $model->monthly]) : null;

                if ($bucket !== null) {
                    [$country, $geo, $deal, $type] = explode('|', $key);
                    $buckets[$country . '|' . $geo . '|' . $deal . '|' . $type . '|' . $bucket][] = ['group' => $key, 'model' => $model];
                }
            }
        }

        // Language twins: one bucket in two languages counts once, where the site has that locale.
        foreach ($buckets as $twins) {
            $languages = array_unique(array_map(static fn ($t) => explode('|', $t['group'])[4], $twins));

            if (count($languages) < 2) {
                continue;
            }

            usort($twins, static function ($a, $b) use ($siteLanguages, $project) {
                $la = explode('|', $a['group'])[4];
                $lb = explode('|', $b['group'])[4];
                $order = static fn ($l, $market) => in_array($l, $siteLanguages, true) ? -100 : (int) array_search($l, (array) ($market['languages'] ?? []), true);
                $market = $project->market(explode('|', $a['group'])[0]);

                return $order($la, $market) <=> $order($lb, $market);
            });

            foreach (array_slice($twins, 1) as $twin) {
                $twin['model']->counted = false;
                $twin['model']->shared = true;
            }
        }

        $map = UrlMap::for($project);
        $notIndexed = Issue::query()->where('project_id', $project->getKey())->where('code', 'not_indexed')->where('status', Issue::OPEN)
            ->pluck('url')->map(static fn ($u) => UrlMap::normalise((string) $u))->all();
        $kept = [];
        $totalReal = $totalRaw = 0;

        foreach ($groups as $key => $members) {
            [$country, $geo, $deal, $type, $lang] = explode('|', $key);
            $real = $ambiguous = $raw = 0;
            $measured = false;
            $primary = null;

            foreach ($members as $member) {
                $model = $member['model'];
                $volume = $model->volume;

                if ($volume !== null) {
                    $measured = true;
                    $raw += $volume;
                }

                if ($model->counted && $volume !== null) {
                    if ($member['ambiguous']) {
                        $ambiguous += $volume;
                    } else {
                        $real += $volume;
                    }
                }

                if ($model->counted && ($primary === null || [!$member['ambiguous'], (int) $volume] > [!$primary['ambiguous'], (int) $primary['model']->volume])) {
                    $primary = $member;
                }
            }

            $target = Target::query()->firstOrNew([
                'project_id' => $project->getKey(), 'country' => $country, 'geo' => $geo, 'deal' => $deal, 'type' => $type, 'lang' => $lang,
            ]);

            $proposed = self::proposedUrl($project, $lexicon, $target->fill(['lang' => $lang]), $geo, $siteLanguages);
            $target->forceFill([
                'proposed_url' => $proposed,
                'primary_keyword_id' => $primary === null ? null : (int) $primary['model']->getKey(),
                'demand_real' => $measured ? $real : null,
                'demand_ambiguous' => $measured ? $ambiguous : null,
                'demand_raw' => $measured ? $raw : null,
            ]);

            self::matchDocument($target, $map, $lexicon, $geo, (array) $project->market($country));
            $target->status = self::status($target, $siteLanguages, $notIndexed);
            $target->save();
            $kept[] = (int) $target->getKey();
            $totalReal += $real;
            $totalRaw += $raw;

            foreach ($members as $member) {
                $member['model']->target_id = (int) $target->getKey();
                $member['model']->save();
            }
        }

        // Origin demand: the same query searched from abroad, reported beside real demand.
        foreach (Target::query()->where('project_id', $project->getKey())->whereIn('id', $kept ?: [0])->get() as $target) {
            $words = Keyword::query()->where('target_id', $target->getKey())->where('counted', true)->pluck('keyword')->all();
            $origin = $words === [] ? 0 : (int) Keyword::query()->where('project_id', $project->getKey())->where('origin', true)
                ->where('market', $target->country)->where('lang', $target->lang)->whereIn('keyword', $words)->sum('volume');
            $target->forceFill(['demand_origin' => $origin ?: null])->save();
        }

        Target::query()->where('project_id', $project->getKey())->whereNotIn('id', $kept ?: [0])
            ->where('document_manual', false)->delete();

        return [
            'targets' => count($kept),
            'keywords' => $keywords->count(),
            'demand_real' => $totalReal,
            'demand_raw' => $totalRaw,
            'ratio' => $totalReal > 0 ? round($totalRaw / $totalReal, 2) : null,
        ];
    }

    /** From the vertical's slug template: `{lang}/{type}-for-{deal}/{geo}`; no language prefix for the site's first language. */
    public static function proposedUrl(Project $project, Lexicon $lexicon, Target $target, string $geo, array $siteLanguages): string
    {
        $lang = (string) $target->lang;
        $pattern = $lexicon->slug($lang, 'pattern', '{lang}/{type}-{deal}/{geo}');
        $path = strtr($pattern, [
            '{lang}' => ($siteLanguages[0] ?? '') === $lang ? '' : $lang,
            '{type}' => $lexicon->slug($lang, 'type.' . ($target->type ?: 'any'), 'property'),
            '{deal}' => $target->deal !== '' ? $lexicon->slug($lang, 'deal.' . $target->deal) : '',
            '{geo}' => self::slugify($geo),
        ]);
        $path = trim((string) preg_replace(['#/+#', '#-+#', '#-/#', '#/-#', '#-$#'], ['/', '-', '/', '/', ''], $path), '/-');

        return 'https://' . $project->host() . '/' . $path;
    }

    public static function slugify(string $text): string
    {
        $text = Text::fold($text);
        $cyr = ['а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'h', 'ґ' => 'g', 'д' => 'd', 'е' => 'e', 'є' => 'ye', 'ж' => 'zh', 'з' => 'z', 'и' => 'y', 'і' => 'i', 'ї' => 'yi', 'й' => 'y',
            'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch',
            'ш' => 'sh', 'щ' => 'shch', 'ь' => '', 'ю' => 'yu', 'я' => 'ya', 'ы' => 'y', 'э' => 'e', 'ъ' => ''];

        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtr($text, $cyr)), '-');
    }

    /**
     * The document serving this target: one the manager assigned, the proposed
     * URL, else the published page whose address carries the same place and
     * type or deal words.
     */
    private static function matchDocument(Target $target, UrlMap $map, Lexicon $lexicon, string $geo, array $market = []): void
    {
        if ($target->document_manual && $target->document_id !== null) {
            return;
        }

        $resource = $map->resolve((string) $target->proposed_url);

        if ($resource === null && $geo !== '') {
            // The place as the page's language spells it: "prague" in an English address, "praha" in a Czech one.
            $geoSlugs = [self::slugify($geo)];

            foreach ((array) ($market['geo'] ?? []) as $place) {
                if ((string) ($place['name'] ?? '') === $geo) {
                    foreach ((array) ($place['spellings'][(string) $target->lang] ?? []) as $spelling) {
                        $geoSlugs[] = self::slugify((string) $spelling);
                    }
                }
            }

            $geoSlugs = array_values(array_unique(array_filter($geoSlugs)));
            $words = array_filter([
                $lexicon->slug((string) $target->lang, 'type.' . ($target->type ?: 'any')),
                $target->deal !== '' ? $lexicon->slug((string) $target->lang, 'deal.' . $target->deal) : '',
            ]);
            $best = 0;

            foreach ($map->urls() as $entry) {
                $path = '/' . trim(mb_strtolower((string) parse_url($entry['url'], PHP_URL_PATH)), '/') . '/';

                $hasPlace = false;

                foreach ($geoSlugs as $geoSlug) {
                    $hasPlace = $hasPlace || str_contains($path, $geoSlug);
                }

                if (!$hasPlace) {
                    continue;
                }

                $score = 0;

                foreach ($words as $word) {
                    $score += str_contains($path, (string) $word) ? 1 : 0;
                }

                if ($entry['lang'] !== 'base' && $entry['lang'] !== $target->lang) {
                    continue;
                }

                if ($score > $best) {
                    $best = $score;
                    $resource = $entry;
                }
            }
        }

        $target->document_id = $resource['id'] ?? null;
        $target->resource_type = $resource['type'] ?? 'document';
        $target->url = $resource['url'] ?? null;
    }

    private static function status(Target $target, array $siteLanguages, array $notIndexed): string
    {
        if ($target->document_id !== null) {
            return in_array(UrlMap::normalise((string) $target->url), $notIndexed, true) ? Target::NOT_INDEXED : Target::EXISTS;
        }

        return in_array($target->lang, $siteLanguages, true) ? Target::MISSING : Target::NEEDS_LOCALE;
    }
}
