<?php

namespace Elcreator\aDataForSeo\Services;

use Carbon\Carbon;
use Elcreator\aDataForSeo\Api\Context;
use Elcreator\aDataForSeo\Api\Credentials;
use Elcreator\aDataForSeo\Api\Ledger;
use Elcreator\aDataForSeo\Gateway;
use Elcreator\aDataForSeo\Integrations\Sites;
use Elcreator\aDataForSeo\Models\Job;
use Elcreator\aDataForSeo\Models\Key;
use Elcreator\aDataForSeo\Models\Project;
use Elcreator\aDataForSeo\Support\Billing;
use Elcreator\aDataForSeo\Support\Config;

/**
 * The project of this site: created on first visit from `site_url`, its
 * markets and languages guessed from the domain and sLang, its budgets and
 * schedules edited by a manager with `adataforseo_manage`.
 */
final class Projects
{
    /** DataForSEO location codes and the local language Labs covers there. */
    public const COUNTRIES = [
        'us' => [2840, 'en'], 'gb' => [2826, 'en'], 'ca' => [2124, 'en'], 'au' => [2036, 'en'], 'ie' => [2372, 'en'],
        'de' => [2276, 'de'], 'at' => [2040, 'de'], 'ch' => [2756, 'de'], 'cz' => [2203, 'cs'], 'sk' => [2703, 'sk'],
        'pl' => [2616, 'pl'], 'ua' => [2804, 'uk'], 'fr' => [2250, 'fr'], 'es' => [2724, 'es'], 'it' => [2380, 'it'],
        'nl' => [2528, 'nl'], 'pt' => [2620, 'pt'], 'ro' => [2642, 'ro'], 'hu' => [2348, 'hu'], 'lt' => [2440, 'lt'],
        'lv' => [2428, 'lv'], 'ee' => [2233, 'et'], 'bg' => [2100, 'bg'], 'se' => [2752, 'sv'], 'dk' => [2208, 'da'],
        'no' => [2578, 'no'], 'fi' => [2246, 'fi'], 'tr' => [2792, 'tr'], 'il' => [2376, 'he'], 'in' => [2356, 'en'],
    ];

    private const TLD = ['uk' => 'gb', 'com' => 'us', 'net' => 'us', 'org' => 'us', 'io' => 'us', 'eu' => 'de'];

    public static function current(string $domainKey = 'default'): Project
    {
        $project = Project::query()->where('domain_key', $domainKey)->first();

        if ($project !== null) {
            return $project;
        }

        $site = (string) evo()->getConfig('site_url', '');
        $host = (string) preg_replace('/^www\./', '', strtolower((string) parse_url($site, PHP_URL_HOST)));

        return Project::query()->create([
            'domain' => $host !== '' ? $host : 'example.com',
            'domain_key' => $domainKey,
            'markets' => [self::guessMarket($host)],
            'competitors' => [],
            'mode' => 'beginner',
            'schedule' => [],
            'settings' => ['site_languages' => Sites::languages() ?: [self::guessMarket($host)['languages'][0]]],
        ]);
    }

    /** @return array{country: string, location_code: int, languages: string[], geo: array} */
    public static function guessMarket(string $host): array
    {
        $tld = strtolower((string) substr(strrchr('.' . $host, '.'), 1));
        $country = self::TLD[$tld] ?? (isset(self::COUNTRIES[$tld]) ? $tld : 'us');
        [$code, $lang] = self::COUNTRIES[$country];

        return ['country' => strtoupper($country), 'location_code' => $code, 'languages' => [$lang], 'geo' => []];
    }

    /** Effective caps: the project's own, else the configuration's. */
    public static function caps(Project $project): array
    {
        return [
            'monthly_cap' => $project->monthly_cap ?? Config::budget('monthly_cap', 5.0),
            'job_cap' => $project->job_cap ?? Config::budget('job_cap', 2.0),
            'approve_above' => $project->approve_above ?? Config::budget('approve_above', 0.5),
        ];
    }

    /**
     * The money context of a job's calls, in the currency the job was planned in. Through
     * the gateway the balance is left out on purpose: the stored figure is only as fresh as
     * the last verify, and the gateway itself refuses (402) a call it cannot pay for.
     */
    public static function context(Project $project, ?Job $job, ?Credentials $credentials, ?Gateway\Credentials $gateway = null): Context
    {
        $caps = self::caps($project);
        $balance = null;
        $currency = $job !== null && $job->currency !== null && $job->currency !== '' ? (string) $job->currency : Billing::currencyOf($project);

        if ($credentials?->keyId !== null && $credentials->env === 'prod') {
            $balance = Key::query()->whereKey($credentials->keyId)->value('balance');
            $balance = $balance === null ? null : (float) $balance;
        }

        return new Context(
            (int) $project->getKey(),
            $job?->getKey() === null ? null : (int) $job->getKey(),
            null,
            $job?->user_id,
            $job === null ? null : (float) ($job->option('job_cap') ?? $caps['job_cap']),
            (float) $caps['monthly_cap'],
            (bool) ($job?->approved ?? false),
            max(1, min(2, (int) ($job?->option('priority', 1) ?? 1))),
            $balance,
            $currency
        );
    }

    /**
     * Save settings from the page. Only `adataforseo_manage` holders get here.
     * `authorized_by` is written from the acting manager whenever the schedule
     * changes — never from the request.
     *
     * @throws WorkbenchException
     */
    public function update(Project $project, array $input, Actor $actor): Project
    {
        $actor->authorize('manage');
        $errors = [];
        $changes = [];

        if (array_key_exists('mode', $input)) {
            $changes['mode'] = in_array($input['mode'], ['beginner', 'pro'], true) ? $input['mode'] : 'beginner';
        }

        if (array_key_exists('billing', $input)) {
            $changes['billing'] = $input['billing'] === Billing::GATEWAY ? Billing::GATEWAY : Billing::OWN;
        }

        if (array_key_exists('domain', $input)) {
            $domain = strtolower(trim((string) $input['domain']));
            $domain = (string) preg_replace(['#^[a-z]+://#', '#/.*$#', '/^www\./'], '', $domain);

            if (!preg_match('/^([a-z0-9.-]+\.[a-z]{2,}|localhost)(:\d{1,5})?$/', $domain)) {
                $errors['domain'] = \Elcreator\aDataForSeo\Support\Lang::get('aDataForSeo::global.error_domain');
            } else {
                $changes['domain'] = $domain;
            }
        }

        foreach (['monthly_cap', 'job_cap', 'approve_above'] as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }

            $value = $input[$field];

            if ($value === null || $value === '') {
                $changes[$field] = null;
            } elseif (!is_numeric($value) || (float) $value < 0 || (float) $value > 100000) {
                $errors[$field] = \Elcreator\aDataForSeo\Support\Lang::get('aDataForSeo::global.error_amount');
            } else {
                $changes[$field] = round((float) $value, 4);
            }
        }

        if (array_key_exists('markets', $input)) {
            $markets = $this->markets($input['markets']);

            if ($markets === null) {
                $errors['markets'] = \Elcreator\aDataForSeo\Support\Lang::get('aDataForSeo::global.error_markets');
            } else {
                $changes['markets'] = $markets;
            }
        }

        if (array_key_exists('competitors', $input)) {
            $list = is_array($input['competitors']) ? $input['competitors'] : preg_split('/[\s,]+/', (string) $input['competitors']);
            $changes['competitors'] = array_values(array_unique(array_filter(array_map(static function ($d) {
                $d = strtolower(trim(is_scalar($d) ? (string) $d : ''));

                return (string) preg_replace(['#^[a-z]+://#', '#/.*$#', '/^www\./'], '', $d);
            }, (array) $list), static fn ($d) => (bool) preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $d))));
        }

        if (array_key_exists('settings', $input) && is_array($input['settings'])) {
            $changes['settings'] = array_replace((array) ($project->settings ?? []), $this->settings($input['settings']));
        }

        if (array_key_exists('schedule', $input) && is_array($input['schedule'])) {
            $schedule = (array) ($project->schedule ?? []);

            foreach (['coverage', 'audit', 'research', 'track'] as $kind) {
                if (array_key_exists($kind, $input['schedule'])) {
                    $days = (int) $input['schedule'][$kind];
                    $schedule[$kind] = $days > 0 ? min(365, $days) : 0;
                }
            }

            $schedule['refused_logged'] = false;
            $changes['schedule'] = $schedule;
            // The manager enabling a schedule is who it will run as, re-checked each time.
            $changes['authorized_by'] = $actor->userId();
        }

        if ($errors !== []) {
            throw WorkbenchException::fields($errors);
        }

        $project->forceFill($changes)->save();

        return $project->refresh();
    }

    /** @return array<int, array<string, mixed>>|null */
    private function markets(mixed $value): ?array
    {
        if (!is_array($value) || $value === [] || count($value) > 10) {
            return null;
        }

        $markets = [];

        foreach ($value as $market) {
            if (!is_array($market)) {
                return null;
            }

            $country = strtolower(trim((string) ($market['country'] ?? '')));

            if (!preg_match('/^[a-z]{2}$/', $country)) {
                return null;
            }

            $code = (int) ($market['location_code'] ?? (self::COUNTRIES[$country][0] ?? 0));
            $languages = array_values(array_unique(array_filter(array_map(
                static fn ($l) => strtolower(trim(is_scalar($l) ? (string) $l : '')),
                is_array($market['languages'] ?? null) ? $market['languages'] : preg_split('/[\s,]+/', (string) ($market['languages'] ?? ''))
            ), static fn ($l) => (bool) preg_match('/^[a-z]{2}$/', $l))));

            if ($code <= 0 || $languages === []) {
                return null;
            }

            $geo = [];

            foreach ((array) ($market['geo'] ?? []) as $place) {
                $name = trim(is_array($place) ? (string) ($place['name'] ?? '') : (string) $place);

                if ($name === '') {
                    continue;
                }

                $spellings = [];

                foreach ((array) (is_array($place) ? ($place['spellings'] ?? []) : []) as $lang => $words) {
                    $words = array_values(array_filter(array_map('trim', is_array($words) ? array_map('strval', $words) : explode(',', (string) $words))));

                    if (preg_match('/^[a-z]{2}$/', (string) $lang) && $words !== []) {
                        $spellings[(string) $lang] = $words;
                    }
                }

                $geo[] = ['name' => mb_substr($name, 0, 64), 'spellings' => $spellings];
            }

            $markets[] = ['country' => strtoupper($country), 'location_code' => $code, 'languages' => $languages, 'geo' => array_slice($geo, 0, 50)];
        }

        return $markets;
    }

    private function settings(array $input): array
    {
        $out = [];

        if (isset($input['weights']) && is_array($input['weights'])) {
            foreach (['w_amb', 'w_origin', 'bonus', 't', 'locale_factor', 'k'] as $w) {
                if (isset($input['weights'][$w]) && is_numeric($input['weights'][$w])) {
                    $out['weights'][$w] = max(0.0, min(10.0, (float) $input['weights'][$w]));
                }
            }
        }

        foreach (['seeds', 'brand_terms', 'site_languages'] as $list) {
            if (array_key_exists($list, $input)) {
                $values = is_array($input[$list]) ? $input[$list] : preg_split('/[\n,]+/', (string) $input[$list]);
                $out[$list] = array_values(array_slice(array_unique(array_filter(array_map(
                    static fn ($v) => mb_substr(trim(is_scalar($v) ? (string) $v : ''), 0, 120),
                    (array) $values
                ))), 0, 200));
            }
        }

        if (isset($input['crawl_pages']) && is_numeric($input['crawl_pages'])) {
            $out['crawl_pages'] = max(10, min(10000, (int) $input['crawl_pages']));
        }

        if (array_key_exists('javascript', $input)) {
            $out['javascript'] = filter_var($input['javascript'], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($input['vertical']) && preg_match('/^[a-z0-9_]+$/', (string) $input['vertical'])) {
            $out['vertical'] = (string) $input['vertical'];
        }

        return $out;
    }

    public static function present(Project $project): array
    {
        $caps = self::caps($project);

        return [
            'id' => (int) $project->getKey(),
            'domain' => $project->domain,
            'domain_key' => $project->domain_key,
            'mode' => $project->mode,
            'billing' => Billing::modeOf($project),
            'markets' => $project->marketList(),
            'competitors' => array_values((array) ($project->competitors ?? [])),
            'schedule' => (array) ($project->schedule ?? []),
            'settings' => (array) ($project->settings ?? []),
            'site_languages' => $project->siteLanguages(),
            'budget' => $caps + [
                'own' => ['monthly_cap' => $project->monthly_cap, 'job_cap' => $project->job_cap, 'approve_above' => $project->approve_above],
                'spent_month' => (new Ledger())->spentMonth((int) $project->getKey(), Billing::currencyOf($project)),
                'currency' => Billing::currencyOf($project),
                'month' => Carbon::now()->format('Y-m'),
            ],
        ];
    }
}
