<?php

namespace Elcreator\aDataForSeo\Analysis\Research;

use Elcreator\aDataForSeo\Engine\Analyser;
use Elcreator\aDataForSeo\Engine\Runtime;
use Elcreator\aDataForSeo\Models\Project;
use Elcreator\aDataForSeo\Models\Step;
use Elcreator\aDataForSeo\Models\Target;
use Elcreator\aDataForSeo\Support\Config;

/**
 * score = (real + w_amb·ambiguous + w_origin·origin) · (1 − kd/100)^k
 *         · (native_share < t ? bonus : 1) · (needs_locale ? locale_factor : 1)
 *
 * `k` follows the site's own strength: 3 for a site with no rankings (with
 * 1.5 a new site's P1 was all national head terms with KD 60–80), lowered
 * towards 1.5 as its ranked keywords grow. A target whose demand was never
 * measured has no score — "not measured", never "no demand".
 * Tiers by rank: the top 20 % P1, the next 30 % P2, the rest P3.
 */
final class Priority extends Analyser
{
    public function run(Runtime $rt, Step $step): array
    {
        $summary = self::apply($rt->project);

        return ['output' => $summary, 'result' => ['priority' => $summary]];
    }

    public static function weights(Project $project): array
    {
        return array_replace(
            ['w_amb' => 0.5, 'w_origin' => 0.5, 'bonus' => 1.25, 't' => 0.5, 'locale_factor' => 0.8],
            (array) Config::get('research.weights', []),
            (array) $project->setting('weights', [])
        );
    }

    /** 3 with nothing ranked, 1.5 from about a thousand ranked keywords. */
    public static function k(Project $project): float
    {
        $own = $project->setting('weights.k');

        if (is_numeric($own)) {
            return (float) $own;
        }

        $ranked = (int) $project->setting('ranked_keywords', 0);

        return round(3 - 1.5 * min(1.0, log10($ranked + 1) / 3), 3);
    }

    public static function score(float $real, float $ambiguous, float $origin, float $kd, float $k, ?float $nativeShare, bool $needsLocale, array $w): float
    {
        $demand = $real + $w['w_amb'] * $ambiguous + $w['w_origin'] * $origin;
        $ease = (1 - max(0.0, min(100.0, $kd)) / 100) ** $k;
        $bonus = $nativeShare !== null && $nativeShare < $w['t'] ? $w['bonus'] : 1.0;
        $locale = $needsLocale ? $w['locale_factor'] : 1.0;

        return round($demand * $ease * $bonus * $locale, 4);
    }

    /** @return array{scored: int, p1: int, p2: int, p3: int, k: float} */
    public static function apply(Project $project): array
    {
        $w = self::weights($project);
        $k = self::k($project);
        $defaults = (array) Config::get('research.default_kd', ['local' => 40, 'foreign' => 20]);
        $scores = [];

        foreach (Target::query()->where('project_id', $project->getKey())->get() as $target) {
            if (!$target->measured()) {
                $target->forceFill(['score' => null, 'tier' => null])->save();

                continue;
            }

            $market = $project->market((string) $target->country);
            $local = (string) ($market['languages'][0] ?? '');
            $kd = $target->kd_serp ?? (float) ($target->lang === $local ? ($defaults['local'] ?? 40) : ($defaults['foreign'] ?? 20));

            $score = self::score((float) $target->demand_real, (float) $target->demand_ambiguous, (float) $target->demand_origin, $kd, $k, $target->native_share, $target->status === Target::NEEDS_LOCALE, $w);
            $target->score = $score;
            $scores[] = $target;
        }

        usort($scores, static fn (Target $a, Target $b) => $b->score <=> $a->score);
        $n = count($scores);
        $tiers = ['P1' => 0, 'P2' => 0, 'P3' => 0];

        foreach ($scores as $rank => $target) {
            $tier = $target->score <= 0 ? 'P3' : ($rank < max(1, (int) ceil($n * 0.2)) ? 'P1' : ($rank < (int) ceil($n * 0.5) ? 'P2' : 'P3'));
            $target->tier = $tier;
            $target->save();
            $tiers[$tier]++;
        }

        return ['scored' => $n, 'p1' => $tiers['P1'], 'p2' => $tiers['P2'], 'p3' => $tiers['P3'], 'k' => $k];
    }
}
