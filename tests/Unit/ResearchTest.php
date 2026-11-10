<?php

use Elcreator\aDataForSeo\Analysis\Research\Classifier;
use Elcreator\aDataForSeo\Analysis\Research\Clusters;
use Elcreator\aDataForSeo\Analysis\Research\Difficulty;
use Elcreator\aDataForSeo\Analysis\Research\Lexicon;
use Elcreator\aDataForSeo\Analysis\Research\Priority;
use Elcreator\aDataForSeo\Analysis\Research\Targets;
use Elcreator\aDataForSeo\Analysis\Research\Text;
use Elcreator\aDataForSeo\Analysis\Research\Universe;
use Elcreator\aDataForSeo\Analysis\Serp;
use Elcreator\aDataForSeo\Models\Keyword;
use Elcreator\aDataForSeo\Models\Target;

/**
 * Demand and the page matrix: every rule here is one a naive version got
 * wrong on a real site (docs/DESIGN.md, Wave 3).
 */

beforeEach(fn () => adfsReset());

function adfsVector(int $volume, int $seed = 1): array
{
    return array_map(static fn ($i) => (int) round($volume * (0.8 + 0.04 * (($i * $seed) % 10))), range(0, 11));
}

describe('Text', function () {
    it('folds diacritics and builds order-free token bags', function () {
        expect(Text::fold('Pronájem BYTŮ'))->toBe('pronajem bytu')
            ->and(Text::bag('apartments for rent atlantis', ['for']))->toBe(Text::bag('atlantis rent apartments', ['for']));
    });

    it('detects languages from marker letters, vocabulary and script', function () {
        $lexicon = new Lexicon();

        expect(Text::detectLang('купити квартиру атлантида', ['uk', 'ru'], $lexicon))->toBe('uk')
            ->and(Text::detectLang('купить квартиру в атлантиде', ['uk', 'ru'], $lexicon))->toBe('ru')
            ->and(Text::detectLang('pronájem bytů atlantida', ['cs', 'en'], $lexicon))->toBe('cs')
            ->and(Text::detectLang('byty atlantida', ['cs', 'en'], $lexicon))->toBe('cs')
            ->and(Text::detectLang('apartments atlantis', ['cs', 'en'], $lexicon))->toBe('en');
    });

    it('matches place names across case endings', function () {
        expect(Text::sameWord('атлантиді', 'атлантида'))->toBeTrue()
            ->and(Text::sameWord('avalon', 'atlantida'))->toBeFalse();
    });
});

describe('Classifier', function () {
    it('finds place, deal and type, and marks brand queries', function () {
        $market = adfsProject()->primaryMarket();
        $classifier = new Classifier(new Lexicon(), ['starlinghomes']);

        expect($classifier->classify('pronájem bytu atlantida', $market, 'cs'))->toMatchArray(['geo' => 'Atlantida', 'deal' => 'rent', 'type' => 'flat', 'brand' => false])
            ->and($classifier->classify('apartments for rent in atlantis', $market, 'en'))->toMatchArray(['geo' => 'Atlantida', 'deal' => 'rent', 'type' => 'flat'])
            ->and($classifier->classify('купити будинок авалон', $market, 'uk'))->toMatchArray(['geo' => 'Avalon', 'deal' => 'sale', 'type' => 'house'])
            ->and($classifier->classify('квартира в атлантиді', $market, 'uk'))->toMatchArray(['geo' => 'Atlantida', 'deal' => '', 'type' => 'flat'])
            ->and($classifier->classify('starlinghomes byty atlantida', $market, 'cs')['brand'])->toBeTrue();
    });
});

describe('Clusters', function () {
    it('counts Ads buckets once: same volume and same 12-month vector', function () {
        $vector = adfsVector(2400, 3);
        $keywords = [];

        foreach (['apartments for rent atlantis', 'atlantis apartments rent', 'rent apartment in atlantis', 'apartment rental atlantis', 'flats to rent atlantis'] as $i => $text) {
            $keywords[] = ['id' => $i + 1, 'keyword' => $text, 'volume' => 2400, 'monthly' => $vector];
        }

        $keywords[] = ['id' => 99, 'keyword' => 'atlantis studio rent', 'volume' => 2400, 'monthly' => adfsVector(2400, 7)];

        $heads = (new Clusters())->build($keywords);

        expect(array_unique(array_intersect_key($heads, array_flip([1, 2, 3, 4, 5]))))->toHaveCount(1)
            ->and($heads[99])->toBe(99);
    });

    it('does not bucket small or flat vectors, but joins by core keyword and token bag', function () {
        $flat = array_fill(0, 12, 30);
        $heads = (new Clusters())->build([
            ['id' => 1, 'keyword' => 'byty atlantida levne', 'volume' => 30, 'monthly' => $flat],
            ['id' => 2, 'keyword' => 'garaz avalon', 'volume' => 30, 'monthly' => $flat],
            ['id' => 3, 'keyword' => 'pronájem bytů', 'volume' => 500, 'monthly' => null, 'core_keyword' => 'pronajem bytu'],
            ['id' => 4, 'keyword' => 'pronajem bytu', 'volume' => 200, 'monthly' => null, 'core_keyword' => 'pronajem bytu'],
            ['id' => 5, 'keyword' => 'atlantida byty', 'volume' => 90, 'monthly' => null],
            ['id' => 6, 'keyword' => 'byty atlantida', 'volume' => 90, 'monthly' => null],
        ]);

        expect($heads[1])->not->toBe($heads[2])
            ->and($heads[3])->toBe(3)->and($heads[4])->toBe(3)
            ->and($heads[5])->toBe($heads[6]);
    });

    it('counts uk/ru twins once, on the page whose locale the site has', function () {
        $project = adfsProject(['settings' => ['site_languages' => ['uk']]]);
        $project->forceFill(['markets' => [array_merge($project->primaryMarket(), ['languages' => ['cs', 'uk', 'ru']])]])->save();
        $vector = adfsVector(480, 5);

        foreach ([['uk', 'купити квартиру атлантида'], ['ru', 'купить квартиру атлантида']] as [$lang, $text]) {
            Keyword::query()->create(['project_id' => $project->id, 'market' => 'CZ', 'lang' => $lang, 'keyword' => $text, 'volume' => 480, 'monthly' => $vector, 'source' => 'ads']);
        }

        Targets::rebuild($project);

        $uk = Target::query()->where('lang', 'uk')->first();
        $ru = Target::query()->where('lang', 'ru')->first();

        expect($uk->demand_real)->toBe(480)->and($ru->demand_real)->toBe(0)
            ->and(Keyword::query()->where('lang', 'ru')->value('shared'))->toBeTrue()
            ->and($ru->status)->toBe(Target::NEEDS_LOCALE);
    });
});

describe('Targets', function () {
    it('separates real, ambiguous and raw demand, and leaves unmeasured demand null', function () {
        $project = adfsProject();
        $vector = adfsVector(1300, 2);
        $rows = [
            ['pronájem bytů atlantida', 1300, $vector],
            ['pronajem bytu atlantida', 1300, $vector],   // the same Ads bucket
            ['byt atlantida', 500, null],                 // no deal: ambiguous → rent
            ['prodej domu avalon', null, null],         // not measured
        ];

        foreach ($rows as [$text, $volume, $monthly]) {
            Keyword::query()->create(['project_id' => $project->id, 'market' => 'CZ', 'lang' => 'cs', 'keyword' => $text, 'volume' => $volume, 'monthly' => $monthly, 'source' => 'ads']);
        }

        $summary = Targets::rebuild($project);
        $rent = Target::query()->where('geo', 'Atlantida')->where('deal', 'rent')->where('type', 'flat')->first();
        $avalon = Target::query()->where('geo', 'Avalon')->first();

        expect($rent->demand_real)->toBe(1300)
            ->and($rent->demand_ambiguous)->toBe(500)
            ->and($rent->demand_raw)->toBe(3100)
            ->and($avalon->demand_real)->toBeNull()
            ->and($avalon->measured())->toBeFalse()
            ->and($summary['ratio'])->toBeGreaterThan(1.0);
    });

    it('proposes URLs from the slug template and finds the existing page', function () {
        $project = adfsProject();
        $id = adfsDocument('pronajem-byty-atlantida');
        Keyword::query()->create(['project_id' => $project->id, 'market' => 'CZ', 'lang' => 'cs', 'keyword' => 'pronájem bytů atlantida', 'volume' => 100, 'source' => 'labs']);
        Keyword::query()->create(['project_id' => $project->id, 'market' => 'CZ', 'lang' => 'en', 'keyword' => 'houses for sale avalon', 'volume' => 50, 'source' => 'labs']);

        Targets::rebuild($project);

        $cs = Target::query()->where('lang', 'cs')->first();
        $en = Target::query()->where('lang', 'en')->first();

        $english = adfsDocument('apartments-for-rent-atlantis');
        \Elcreator\aDataForSeo\Support\UrlMap::invalidate(); // what the document-save listener does
        Keyword::query()->create(['project_id' => $project->id, 'market' => 'CZ', 'lang' => 'en', 'keyword' => 'apartments for rent atlantis', 'volume' => 70, 'source' => 'labs']);
        Targets::rebuild($project);
        $cs = Target::query()->where('lang', 'cs')->first();
        $en = Target::query()->where('lang', 'en')->where('geo', 'Avalon')->first();

        // The English page spells the place "atlantis": matched through the market's spellings.
        expect(Target::query()->where('lang', 'en')->where('geo', 'Atlantida')->value('document_id'))->toBe($english);

        expect($cs->document_id)->toBe($id)->and($cs->status)->toBe(Target::EXISTS)
            ->and($en->proposed_url)->toBe('https://example.test/en/houses-for-sale/avalon')
            ->and($en->status)->toBe(Target::MISSING);
    });

    it('builds page addresses from the project domain, not the CMS site_url, and ignores a port for the host', function () {
        AdfsTestCore::$config['site_url'] = 'http://localhostcore/';
        $project = adfsProject(['domain' => 'localhost:8091']);
        adfsDocument('kontakty');

        $urls = \Elcreator\aDataForSeo\Support\UrlMap::for($project)->urls();

        expect(\Elcreator\aDataForSeo\Support\UrlMap::withoutCliBase('/core/kontakty/', '/core/', true))->toBe('/kontakty/')
            ->and(\Elcreator\aDataForSeo\Support\UrlMap::withoutCliBase('/core/kontakty/', 'core/', true))->toBe('/kontakty/')
            ->and(\Elcreator\aDataForSeo\Support\UrlMap::withoutCliBase('/core/kontakty/', '/core/', false))->toBe('/core/kontakty/')
            ->and(\Elcreator\aDataForSeo\Support\UrlMap::withoutCliBase('/sub/kontakty/', '/sub/', true))->toBe('/sub/kontakty/');

        expect($project->host())->toBe('localhost')
            ->and($urls[0]['url'])->toBe('http://localhost:8091/kontakty')
            ->and(\Elcreator\aDataForSeo\Support\UrlMap::for($project)->resolve('https://localhost/kontakty/')['id'])->toBe($urls[0]['id']);
    });

    it('builds friendly paths from the aliases when the CMS alias cache is stale', function () {
        AdfsTestCore::$staleAliases = true;
        AdfsTestCore::$config += ['friendly_urls' => 1, 'use_alias_path' => 1, 'friendly_url_suffix' => '/', 'site_start' => 1];
        $project = adfsProject();
        $home = adfsDocument('home');
        $parent = adfsDocument('byty');
        $child = adfsDocument('pronajem-byty-atlantida', ['parent' => $parent]);
        AdfsTestCore::$config['site_start'] = $home;

        $urls = array_column(\Elcreator\aDataForSeo\Support\UrlMap::for($project)->urls(), 'url', 'id');

        expect($urls[$home])->toBe('https://example.test/')
            ->and($urls[$child])->toBe('https://example.test/byty/pronajem-byty-atlantida/');

        AdfsTestCore::$config['friendly_urls'] = 0;
        expect(\Elcreator\aDataForSeo\Support\UrlMap::aliasPath($child, [$child => ['alias' => 'x', 'parent' => 0]]))->toBeNull();
    });

    it('normalises Labs and Google Ads answers into the same row', function () {
        $labs = Universe::normalise(['keyword_data' => ['keyword' => 'Byty Atlantida', 'keyword_info' => ['search_volume' => 900, 'monthly_searches' => [['year' => 2026, 'month' => 2, 'search_volume' => 2], ['year' => 2025, 'month' => 12, 'search_volume' => 1]]], 'keyword_properties' => ['core_keyword' => 'byty atlantida', 'keyword_difficulty' => null]]], 'labs');
        $ads = Universe::normalise(['keyword' => 'byty atlantida', 'search_volume' => 900, 'competition' => 'HIGH', 'competition_index' => 80], 'ads');

        expect($labs['keyword'])->toBe('byty atlantida')->and($labs['monthly'])->toBe([1, 2])->and($labs['kd_labs'])->toBeNull()
            ->and($ads['volume'])->toBe(900)->and($ads['competition'])->toBe(0.8);
    });
});

describe('SERP and difficulty', function () {
    it('reads the real host behind google.com/goto wrappers and from the breadcrumb', function () {
        $results = Serp::organic(adfsTask([['items' => [
            ['type' => 'organic', 'rank_group' => 1, 'url' => 'https://www.google.com/goto?url=https%3A%2F%2Fwww.starlinghomes.test%2Fhledani', 'domain' => 'www.google.com', 'title' => 'Starling Homes', 'breadcrumb' => 'https://www.starlinghomes.test › hledani'],
            ['type' => 'organic', 'rank_group' => 2, 'url' => null, 'domain' => null, 'title' => 'Byty Atlantida', 'breadcrumb' => 'https://www.moonlitflats.test › pronajem › byt'],
            ['type' => 'organic', 'rank_group' => 3, 'url' => null, 'domain' => null, 'title' => 'Pronájem bytů — skupina', 'breadcrumb' => '12K+ followers'],
            ['type' => 'organic', 'rank_group' => 4, 'url' => 'https://www.facebook.com/groups/123', 'title' => 'FB', 'breadcrumb' => ''],
            ['type' => 'paid', 'rank_group' => 1, 'url' => 'https://ads.example/', 'title' => 'Ad'],
        ]]]));

        expect(array_column($results, 'host'))->toBe(['starlinghomes.test', 'moonlitflats.test', null, 'facebook.com'])
            ->and($results[0]['url'])->toBe('https://www.starlinghomes.test/hledani')
            ->and($results[1]['url'])->toBe('https://moonlitflats.test/pronajem/byt')
            ->and(array_column($results, 'ugc'))->toBe([false, false, true, true]);
    });

    it('scores the top 10 by position-weighted strength, halving foreign-language results for a foreign page', function () {
        $results = [
            ['position' => 1, 'host' => 'big.cz', 'title' => 'Pronájem bytů Atlantida', 'url' => null, 'ugc' => false],
            ['position' => 2, 'host' => null, 'title' => 'Квартири Атлантида', 'url' => null, 'ugc' => true],
        ];
        $traffic = ['big.cz' => 999999.0];

        $local = Difficulty::score($results, $traffic, 'cs', 'cs', new Lexicon(), ['cs', 'uk']);
        $foreign = Difficulty::score($results, $traffic, 'uk', 'cs', new Lexicon(), ['cs', 'uk']);

        $w1 = 1.0;
        $w2 = 1 / log(3, 2);
        expect($local['kd'])->toBe(round((100 * $w1 + 15 * $w2) / ($w1 + $w2), 2))
            ->and($foreign['kd'])->toBe(round((50 * $w1 + 15 * $w2) / ($w1 + $w2), 2))
            ->and($foreign['native_share'])->toBe(0.5)
            ->and(Difficulty::strength(['ugc' => false, 'host' => 'unknown.cz'], []))->toBe(10.0)
            ->and(Difficulty::score([], [], 'cs', 'cs')['kd'])->toBeNull();
    });
});

describe('Priority', function () {
    it('applies the formula', function () {
        $w = ['w_amb' => 0.5, 'w_origin' => 0.5, 'bonus' => 1.25, 't' => 0.5, 'locale_factor' => 0.8];

        expect(Priority::score(1000, 200, 100, 50, 3, 0.9, false, $w))->toBe(round(1150 * 0.125, 4))
            ->and(Priority::score(1000, 0, 0, 0, 3, 0.2, true, $w))->toBe(round(1000 * 1.25 * 0.8, 4));
    });

    it('chooses k from the site strength', function () {
        $project = adfsProject();
        expect(Priority::k($project))->toBe(3.0);

        $project->forceFill(['settings' => ['ranked_keywords' => 999]])->save();
        expect(Priority::k($project))->toBe(1.5);
    });

    it('ranks into P1/P2/P3 and leaves unmeasured targets without a score', function () {
        $project = adfsProject();

        foreach ([5000, 3000, 1000, 500, 100] as $i => $demand) {
            Target::query()->create(['project_id' => $project->id, 'country' => 'CZ', 'geo' => 'g' . $i, 'deal' => 'rent', 'type' => 'flat', 'lang' => 'cs', 'demand_real' => $demand, 'demand_ambiguous' => 0, 'status' => 'missing']);
        }

        Target::query()->create(['project_id' => $project->id, 'country' => 'CZ', 'geo' => 'x', 'deal' => 'rent', 'type' => 'flat', 'lang' => 'cs', 'status' => 'missing']);

        $summary = Priority::apply($project);

        expect($summary)->toMatchArray(['scored' => 5, 'p1' => 1, 'p2' => 2, 'p3' => 2])
            ->and(Target::query()->where('geo', 'x')->value('score'))->toBeNull()
            ->and(Target::query()->where('geo', 'g0')->value('tier'))->toBe('P1');
    });
});
