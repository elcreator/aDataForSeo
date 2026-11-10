<?php

namespace Elcreator\aDataForSeo\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One site (or one sMultisite domain) the plugin works for.
 *
 * `markets`: [{country, location_code, languages[], geo: [{name, spellings: {lang: [...]}}]}].
 * `settings`: weights, site languages, seeds, brand terms, ranked keyword count.
 */
class Project extends Model
{
    protected $table = 'adfs_projects';

    protected $guarded = [];

    protected $casts = [
        'markets' => 'array',
        'competitors' => 'array',
        'schedule' => 'array',
        'settings' => 'array',
        'monthly_cap' => 'float',
        'job_cap' => 'float',
        'approve_above' => 'float',
        'authorized_by' => 'integer',
    ];

    public function setting(string $key, $default = null)
    {
        return data_get(is_array($this->settings) ? $this->settings : [], $key, $default);
    }

    /** @return array<int, array<string, mixed>> */
    public function marketList(): array
    {
        return array_values(array_filter(is_array($this->markets) ? $this->markets : [], 'is_array'));
    }

    public function primaryMarket(): ?array
    {
        return $this->marketList()[0] ?? null;
    }

    public function market(string $country): ?array
    {
        foreach ($this->marketList() as $market) {
            if (strtolower((string) ($market['country'] ?? '')) === strtolower($country)) {
                return $market;
            }
        }

        return null;
    }

    /** The bare host: no scheme, no www, no path. */
    public function host(): string
    {
        $domain = strtolower(trim((string) $this->domain));
        $domain = (string) preg_replace('#^[a-z]+://#', '', $domain);
        $domain = explode('/', $domain)[0];
        $domain = (string) preg_replace('/:\d+$/', '', $domain);

        return (string) preg_replace('/^www\./', '', $domain);
    }

    /** Languages the site already has pages in (sLang, else the first market's first language). */
    public function siteLanguages(): array
    {
        $languages = array_values(array_filter(array_map('strval', (array) $this->setting('site_languages', []))));

        if ($languages !== []) {
            return $languages;
        }

        $first = $this->primaryMarket();

        return [(string) (($first['languages'] ?? ['en'])[0] ?? 'en')];
    }

    /** @return string[] lower-cased brand words never counted as demand */
    public function brandTerms(): array
    {
        $terms = array_map(static fn ($t) => mb_strtolower(trim((string) $t)), (array) $this->setting('brand_terms', []));
        $own = explode('.', $this->host())[0] ?? '';
        $terms[] = $own;

        foreach ((array) $this->competitors as $competitor) {
            $host = (string) preg_replace('/^www\./', '', strtolower((string) (is_array($competitor) ? ($competitor['domain'] ?? '') : $competitor)));
            $terms[] = explode('.', $host)[0] ?? '';
        }

        return array_values(array_unique(array_filter($terms, static fn ($t) => mb_strlen($t) >= 3)));
    }
}
