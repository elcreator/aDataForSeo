<?php

namespace Elcreator\aDataForSeo\Services;

use Elcreator\aDataForSeo\Analysis\Research\Lexicon;
use Elcreator\aDataForSeo\Api\ApiException;
use Elcreator\aDataForSeo\Api\Context;
use Elcreator\aDataForSeo\Api\Estimate;
use Elcreator\aDataForSeo\Gateway\Client;
use Elcreator\aDataForSeo\Models\Issue;
use Elcreator\aDataForSeo\Models\Keyword;
use Elcreator\aDataForSeo\Models\Project;
use Elcreator\aDataForSeo\Models\Target;
use Elcreator\aDataForSeo\Support\Billing;
use Elcreator\aDataForSeo\Support\Keys;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Title and description suggestions, `gateway` driver: ai.artur.work's `/v1/seo/meta`
 * writes variants from the page's own data, sent as `facts`, and refuses a text that
 * states anything the facts do not hold. Off unless the project's `writer` setting is
 * `gateway`; paid from the manager's (else the site's) gateway key in euros, whatever
 * way the project pays for its analytics.
 *
 * Nothing here writes to the site: variants fill the fix dialog, and the manager's
 * "Apply" goes through {@see \Elcreator\aDataForSeo\Fixes\SeoFields} as before.
 */
final class GatewayWriter
{
    public const SETTING = 'writer';
    public const DRIVER = 'gateway';
    public const VARIANTS = 3;

    private const SIBLINGS = 200;

    public function __construct(private readonly Actor $actor, private readonly Project $project)
    {
    }

    public static function enabled(Project $project): bool
    {
        return $project->setting(self::SETTING) === self::DRIVER;
    }

    /** What three variants would cost, from the gateway itself (free, runs nothing). */
    public function quote(Issue|Target $subject): array
    {
        [$client, $body] = $this->prepare($subject);

        return ['estimate' => $client->estimator()->quote('meta', $body)->toArray(), 'currency' => Billing::CURRENCY_GATEWAY];
    }

    /**
     * Ask for the variants. The page showed the quote before this click, so `confirmed`
     * approves an unknown or large price as the explorer's confirmation does.
     */
    public function meta(Issue|Target $subject, bool $confirmed): array
    {
        [$client, $body] = $this->prepare($subject);
        $estimate = $client->estimator()->quote('meta', $body);
        $caps = Projects::caps($this->project);

        if (!$confirmed && ($estimate->isUnknown() || $estimate->ceiling() > (float) $caps['approve_above'])) {
            return ['needs_confirmation' => true, 'estimate' => $estimate->toArray(), 'currency' => Billing::CURRENCY_GATEWAY];
        }

        $context = new Context((int) $this->project->getKey(), null, null, $this->actor->userId(), null, (float) $caps['monthly_cap'], $confirmed, 1, null, Billing::CURRENCY_GATEWAY);
        try {
            $answer = $client->write('meta', $body, (string) Str::uuid(), $context, $estimate);
        } catch (ApiException $e) {
            // The gateway refused the text it wrote (and charged for it): the manager's answer, not a gateway fault.
            throw $e->error === 'writer_refused' ? new WorkbenchException('writer_refused', $e->getMessage(), 422) : $e;
        }

        return self::present($answer, $estimate);
    }

    /** @return array{0: Client, 1: array} */
    private function prepare(Issue|Target $subject): array
    {
        $this->actor->authorize('spend');

        if (!self::enabled($this->project)) {
            throw WorkbenchException::refused('error_writer_off');
        }

        $document = (int) ($subject->document_id ?? 0);

        if ($document <= 0) {
            throw WorkbenchException::invalid('error_fix_no_document');
        }

        if ($subject instanceof Issue && ($subject->fix !== 'seo_fields' || array_intersect_key((array) $subject->detail('suggest', []), ['meta_title' => 1, 'meta_description' => 1]) === [])) {
            throw WorkbenchException::invalid('error_writer_unsupported');
        }

        // The page's text leaves the site: only someone who may edit the page sends it.
        if (!$this->actor->principal()->mayEditDocument($document)) {
            throw WorkbenchException::forbidden('writer for document ' . $document . ' by user ' . $this->actor->userId());
        }

        $credentials = Keys::gatewayFor($this->actor->principal());

        if ($credentials === null) {
            throw WorkbenchException::refused('error_writer_no_key');
        }

        return [new Client($credentials), self::body($this->project, $subject)];
    }

    /**
     * The `/v1/seo/meta` request: the keyword the page should rank for, the page as it is,
     * the titles of the pages beside it (so a variant does not repeat them) and the facts
     * the site vouches for. Only what the site already holds goes into `facts`: the gateway
     * treats any figure or name outside them as an invented claim.
     */
    public static function body(Project $project, Issue|Target $subject): array
    {
        $document = Writer::document((int) $subject->document_id) ?? [];
        $target = $subject instanceof Target ? $subject : Target::query()->where('project_id', $project->getKey())->where('document_id', $subject->document_id)->orderByDesc('score')->first();
        $lang = self::language($project, $subject, $target);
        $title = trim($document['longtitle'] ?? '') !== '' ? trim($document['longtitle']) : trim($document['pagetitle'] ?? '');
        $primary = $target?->primaryKeyword()->value('keyword');
        $keyword = mb_substr(trim((string) ($primary ?: $title)), 0, 120);

        if ($keyword === '') {
            throw WorkbenchException::invalid('error_writer_no_keyword');
        }

        $secondary = $target === null ? [] : Keyword::query()->where('project_id', $project->getKey())->where('target_id', $target->getKey())
            ->where('keyword', '!=', $keyword)->orderByDesc('volume')->limit(30)->pluck('keyword')
            ->map(static fn ($k) => trim((string) $k))->filter(static fn ($k) => $k !== '' && mb_strlen($k) <= 120)->unique()->take(10)->values()->all();

        return array_filter([
            'lang' => $lang,
            'target' => array_filter(['keyword' => $keyword, 'secondary' => $secondary]),
            'page' => array_filter([
                'title' => mb_substr($title, 0, 300),
                'h1' => mb_substr(trim($document['pagetitle'] ?? ''), 0, 300),
                'summary' => mb_substr(Writer::description($document), 0, 1000),
                'url' => mb_substr((string) ($target?->url ?: ($subject instanceof Issue ? $subject->url : '')), 0, 500),
            ]),
            'facts' => self::facts($project, $target, $lang, (int) $subject->document_id),
            'variants' => self::VARIANTS,
            'siblings' => self::siblings((int) $subject->document_id, (int) ($document['parent'] ?? 0)),
        ], static fn ($v) => $v !== [] && $v !== null);
    }

    /** The page's language as a code the gateway knows; sSeo's `base` means the site's own. */
    private static function language(Project $project, Issue|Target $subject, ?Target $target): string
    {
        $lang = strtolower((string) ($subject instanceof Issue ? $subject->detail('lang', '') : $subject->lang));

        if (!preg_match('/^[a-z]{2}$/', $lang)) {
            $lang = strtolower((string) ($target?->lang ?? ''));
        }

        return preg_match('/^[a-z]{2}$/', $lang) ? $lang : strtolower($project->siteLanguages()[0] ?? 'en');
    }

    /** @return array<string, mixed> */
    private static function facts(Project $project, ?Target $target, string $lang, int $document): array
    {
        $facts = [];
        $site = Writer::siteName();

        if ($site !== '') {
            $facts['siteName'] = mb_substr($site, 0, 1000);
        }

        if ($target !== null && (string) $target->geo !== '') {
            $facts['place'] = self::place($project, $target);
        }

        $lexicon = new Lexicon($project->setting('vertical'));

        if ($target !== null && $lexicon->has($lang)) {
            if ((string) $target->deal !== '' && ($deal = $lexicon->word($lang, 'deal.' . $target->deal)) !== '') {
                $facts['deal'] = $deal;
            }

            if ((string) $target->type !== '' && $target->type !== 'any' && ($type = $lexicon->word($lang, 'type.' . $target->type)) !== '') {
                $facts['propertyType'] = $type;
            }
        }

        // A section's own count is real data the template driver has no words for ("12 flats").
        $children = (int) DB::table('site_content')->where('parent', $document)->where('published', 1)->where('deleted', 0)->count();

        if ($children >= 2) {
            $facts['pagesInSection'] = $children;
        }

        return $facts;
    }

    /** The place in each language the project spells it in, else its name. */
    private static function place(Project $project, Target $target): string|array
    {
        foreach ((array) ($project->market((string) $target->country)['geo'] ?? []) as $place) {
            if (mb_strtolower((string) ($place['name'] ?? '')) !== mb_strtolower((string) $target->geo)) {
                continue;
            }

            $spellings = [];

            foreach ((array) ($place['spellings'] ?? []) as $lang => $words) {
                $first = trim((string) (((array) $words)[0] ?? ''));

                if (preg_match('/^[a-z]{2}$/', (string) $lang) && $first !== '') {
                    $spellings[(string) $lang] = mb_substr($first, 0, 1000);
                }
            }

            return $spellings !== [] ? $spellings : (string) $place['name'];
        }

        return (string) $target->geo;
    }

    /** @return string[] titles of the published pages beside this one */
    private static function siblings(int $document, int $parent): array
    {
        return DB::table('site_content')->where('parent', $parent)->where('id', '!=', $document)->where('published', 1)->where('deleted', 0)
            ->orderBy('menuindex')->limit(self::SIBLINGS)->get(['pagetitle', 'longtitle'])
            ->map(static fn ($row) => mb_substr(trim((string) ($row->longtitle ?: $row->pagetitle)), 0, 300))
            ->filter(static fn ($t) => $t !== '')->unique()->values()->all();
    }

    private static function present(array $answer, Estimate $estimate): array
    {
        $variants = [];

        foreach ((array) ($answer['variants'] ?? []) as $v) {
            if (!is_array($v) || trim((string) ($v['title'] ?? '')) === '') {
                continue;
            }

            $variants[] = [
                'title' => trim((string) $v['title']),
                'description' => trim((string) ($v['description'] ?? '')),
                'title_chars' => (int) ($v['titleChars'] ?? mb_strlen((string) $v['title'])),
                'description_chars' => (int) ($v['descriptionChars'] ?? mb_strlen((string) ($v['description'] ?? ''))),
                // `@keyword`, `@title` ... are the gateway's names for the page data we sent; only facts are shown as sources.
                'claims' => array_values(array_map(static fn ($c) => ['text' => (string) ($c['text'] ?? ''), 'keys' => array_values(array_filter(array_map('strval', (array) ($c['keys'] ?? [])), static fn ($k) => $k !== '' && $k[0] !== '@'))], array_filter((array) ($v['claims'] ?? []), 'is_array'))),
            ];
        }

        return [
            'variants' => $variants,
            'warnings' => array_values(array_map(static fn ($w) => ['code' => (string) ($w['code'] ?? ''), 'message' => (string) ($w['message'] ?? '')], array_filter((array) ($answer['warnings'] ?? []), 'is_array'))),
            'generated' => (bool) ($answer['provenance']['generated'] ?? true),
            'estimate' => $estimate->toArray(),
            'cost' => round(is_numeric($answer['chargedEur'] ?? null) ? (float) $answer['chargedEur'] : 0.0, 6),
            'currency' => Billing::CURRENCY_GATEWAY,
        ];
    }
}
