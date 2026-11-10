<?php

use Elcreator\aDataForSeo\Http\Controllers\BootstrapController;
use Elcreator\aDataForSeo\Http\Controllers\CardController;
use Elcreator\aDataForSeo\Http\Controllers\DataController;
use Elcreator\aDataForSeo\Http\Controllers\ExplorerController;
use Elcreator\aDataForSeo\Http\Controllers\JobController;
use Elcreator\aDataForSeo\Http\Controllers\SettingsController;
use Elcreator\aDataForSeo\Listeners\DashboardWidget;
use Elcreator\aDataForSeo\Mcp\Operations;
use Elcreator\aDataForSeo\Models\Issue;
use Elcreator\aDataForSeo\Models\Key;
use Elcreator\aDataForSeo\Models\Target;
use Elcreator\aDataForSeo\Services\Actor;
use Elcreator\aDataForSeo\Services\Exports;
use Elcreator\aDataForSeo\Services\WorkbenchException;
use Elcreator\aDataForSeo\Support\Crypt;
use Elcreator\aDataForSeo\Support\Keys;
use Elcreator\aDataForSeo\Support\Lang;
use Illuminate\Http\Request;

/**
 * What managers, agents and the dashboard see: the HTTP contract, keys that
 * never leak, exports, translations, MCP operations, and the conventions the
 * CMS imposes on code.
 */

beforeEach(fn () => adfsReset());

function adfsJson($response): array
{
    return json_decode((string) $response->getContent(), true);
}

describe('HTTP', function () {
    it('answers bootstrap with the contract fields and never the password', function () {
        adfsUser(5, ['adataforseo', 'adataforseo_spend', 'adataforseo_manage']);
        AdfsTestCore::$loginUserId = 5;
        Keys::save('site', null, 'login@example.test', 'hunter2', 'prod');

        $body = adfsJson((new BootstrapController())->show());

        expect($body['ok'])->toBeTrue()
            ->and($body)->toHaveKeys(['mode', 'project', 'markets', 'key', 'budget', 'queue', 'flags', 'csrf_token'])
            ->and($body['budget'])->toHaveKeys(['monthly_cap', 'spent_month', 'job_cap', 'approve_above'])
            ->and($body['flags'])->toHaveKeys(['may_spend', 'may_manage', 'sseo', 'slang', 'smultisite', 'stask'])
            ->and($body['key']['login'])->toBe('l••••@example.test')
            ->and(json_encode($body))->not->toContain('hunter2')
            ->and(Key::query()->value('password_enc'))->not->toContain('hunter2');
    });

    it('answers 403 without the module permission, and logs the refusal', function () {
        adfsUser(5, ['save_document']);
        AdfsTestCore::$loginUserId = 5;

        $response = (new BootstrapController())->show();

        expect($response->getStatusCode())->toBe(403)->and(adfsJson($response)['error'])->toBe('forbidden')
            ->and(\Elcreator\aDataForSeo\Support\EventLog::$lines)->not->toBeEmpty();
    });

    it('keeps the stored password when the field is left blank and refuses a new login without one', function () {
        adfsUser(5, ['adataforseo', 'adataforseo_spend', 'adataforseo_manage']);
        AdfsTestCore::$loginUserId = 5;
        Keys::save('site', null, 'login@example.test', 'hunter2', 'prod');

        $same = (new SettingsController())->key(Request::create('/key', 'POST', ['scope' => 'site', 'env' => 'sandbox', 'login' => 'login@example.test', 'password' => '', 'verify' => false]));
        $changed = (new SettingsController())->key(Request::create('/key', 'POST', ['scope' => 'site', 'env' => 'prod', 'login' => 'other@example.test', 'password' => '', 'verify' => false]));

        expect($same->getStatusCode())->toBe(200)
            ->and(Crypt::decrypt(Key::query()->value('password_enc')))->toBe('hunter2')
            ->and(Key::query()->value('env'))->toBe('sandbox')
            ->and($changed->getStatusCode())->toBe(422);
    });

    it('removes everything the demo produced once a real login is saved, and never goes back to demo', function () {
        adfsUser(5, ['adataforseo', 'adataforseo_spend', 'adataforseo_manage']);
        AdfsTestCore::$loginUserId = 5;
        adfsDemoKey();
        $project = adfsProject();
        adfsDocument('pronajem-byty-atlantida');
        $job = (new \Elcreator\aDataForSeo\Services\Jobs())->start('audit', $project, ['crawl_pages' => 10], Actor::for(5));
        adfsDrive($job);
        \Elcreator\aDataForSeo\Models\Change::query()->create(['project_id' => $project->id, 'user_id' => 5, 'target' => 'sseo', 'field' => 'meta_title', 'applied_at' => now()]);
        $realCall = \Elcreator\aDataForSeo\Models\Call::query()->create(['project_id' => $project->id, 'env' => 'prod', 'endpoint' => 'serp/google/organic/task_post', 'cost' => 0.0006, 'fetched_at' => now()]);

        expect(Issue::query()->count())->toBeGreaterThan(0)
            ->and(\Elcreator\aDataForSeo\Services\DemoData::present())->toBeTrue();

        // A login DataForSEO rejects changes nothing: the demo key and its data stay.
        \Elcreator\aDataForSeo\Api\Client::useTransport(new AdfsFakeTransport(['#.#' => fn () => [401, '{"status_code":40100,"status_message":"no"}']]));
        $rejected = (new SettingsController())->key(Request::create('/key', 'POST', ['scope' => 'site', 'env' => 'prod', 'login' => 'typo@example.test', 'password' => 'wrong', 'verify' => true]));
        \Elcreator\aDataForSeo\Api\Client::useTransport(null);

        expect($rejected->getStatusCode())->toBe(502)
            ->and(Key::query()->value('env'))->toBe('demo')
            ->and(Issue::query()->count())->toBeGreaterThan(0);

        $response = (new SettingsController())->key(Request::create('/key', 'POST', ['scope' => 'site', 'env' => 'prod', 'login' => 'login@example.test', 'password' => 'hunter2', 'verify' => false]));
        $body = adfsJson($response);

        expect($response->getStatusCode())->toBe(200)
            ->and($body['demo_forgotten']['jobs'])->toBe(1)
            ->and(\Elcreator\aDataForSeo\Models\Job::query()->count())->toBe(0)
            ->and(\Elcreator\aDataForSeo\Models\Step::query()->count())->toBe(0)
            ->and(Issue::query()->count())->toBe(0)
            ->and(\Elcreator\aDataForSeo\Models\Call::query()->pluck('id')->all())->toBe([$realCall->id])
            ->and(\Elcreator\aDataForSeo\Models\Change::query()->count())->toBe(1)
            ->and($project->refresh()->markets[0]['country'])->toBe('CZ')
            ->and(is_dir(\Elcreator\aDataForSeo\Support\Config::storagePath('jobs/' . $job->uuid)))->toBeFalse();

        $back = (new SettingsController())->key(Request::create('/key', 'POST', ['scope' => 'site', 'env' => 'demo', 'verify' => false]));
        expect($back->getStatusCode())->toBe(409)->and(Key::query()->value('env'))->toBe('prod');
    });

    it('lets only managers change project settings', function () {
        adfsUser(5, ['adataforseo', 'adataforseo_spend']);
        AdfsTestCore::$loginUserId = 5;

        expect((new SettingsController())->project(Request::create('/project', 'POST', ['mode' => 'pro']))->getStatusCode())->toBe(403);
    });

    it('validates markets and budgets', function () {
        adfsUser(5, ['adataforseo', 'adataforseo_manage']);
        AdfsTestCore::$loginUserId = 5;
        adfsProject();

        $bad = (new SettingsController())->project(Request::create('/project', 'POST', ['markets' => [['country' => 'XYZ']], 'monthly_cap' => -1]));
        $good = (new SettingsController())->project(Request::create('/project', 'POST', ['markets' => [['country' => 'pl', 'languages' => 'pl, uk', 'geo' => [['name' => 'Atlantyda', 'spellings' => ['uk' => 'Атлантида']]]]], 'monthly_cap' => '3']));

        expect($bad->getStatusCode())->toBe(422)->and(adfsJson($bad)['errors'])->toHaveKeys(['markets', 'monthly_cap'])
            ->and($good->getStatusCode())->toBe(200)
            ->and(adfsJson($good)['project']['markets'][0])->toMatchArray(['country' => 'PL', 'location_code' => 2616, 'languages' => ['pl', 'uk']])
            ->and(adfsJson($good)['project']['markets'][0]['geo'][0]['spellings']['uk'])->toBe(['Атлантида']);
    });

    it('estimates a job for a reader but starts it only for a spender', function () {
        adfsUser(5, ['adataforseo']);
        AdfsTestCore::$loginUserId = 5;
        adfsDemoKey();
        adfsProject();

        $estimate = adfsJson((new JobController())->estimate(Request::create('/jobs/estimate', 'POST', ['kind' => 'audit', 'options' => ['crawl_pages' => 100]])));
        $start = (new JobController())->start(Request::create('/jobs', 'POST', ['kind' => 'audit']));

        expect($estimate['estimate']['amount'])->toBe(0.015)->and($start->getStatusCode())->toBe(403);
    });

    it('asks for confirmation in the explorer when the price is unknown or high', function () {
        adfsUser(5, ['adataforseo', 'adataforseo_spend', 'adataforseo_manage']);
        AdfsTestCore::$loginUserId = 5;
        adfsDemoKey();
        adfsProject();

        $unknown = adfsJson((new ExplorerController())->run(Request::create('/explorer/run', 'POST', ['endpoint' => 'serp/google/organic/live/advanced', 'payload' => '[{"keyword":"x","location_code":2840,"language_code":"en","depth":100}]'])));
        $cheap = adfsJson((new ExplorerController())->run(Request::create('/explorer/run', 'POST', ['endpoint' => 'dataforseo_labs/google/keyword_suggestions/live', 'payload' => '{"keyword":"byty atlantida","location_code":2203,"language_code":"cs","limit":10}'])));
        $bad = (new ExplorerController())->run(Request::create('/explorer/run', 'POST', ['endpoint' => '../../etc', 'payload' => '']));

        expect($unknown['needs_confirmation'])->toBeTrue()
            ->and($cheap['response']['tasks'][0]['status_code'])->toBe(20000)
            ->and($cheap['cost'])->toBeGreaterThan(0.0)
            ->and($bad->getStatusCode())->toBe(422);
    });

    it('serves cards, the matrix and exports', function () {
        adfsUser(5, ['adataforseo']);
        AdfsTestCore::$loginUserId = 5;
        $project = adfsProject();
        Issue::query()->create(['project_id' => $project->id, 'source' => 'audit', 'code' => 'empty_title', 'severity' => 'high', 'url' => '=HYPERLINK("x")', 'url_hash' => 'a', 'fix' => 'seo_fields', 'status' => 'open']);
        Target::query()->create(['project_id' => $project->id, 'country' => 'CZ', 'geo' => 'Atlantida', 'deal' => 'rent', 'type' => 'flat', 'lang' => 'cs', 'status' => 'missing', 'demand_real' => 100, 'demand_raw' => 300, 'tier' => 'P1', 'score' => 10]);

        $cards = adfsJson((new CardController())->index());
        $matrix = adfsJson((new DataController())->matrix(Request::create('/matrix')));
        $csv = (new DataController())->export('issues', 'csv');
        $xlsx = (new DataController())->export('matrix', 'xlsx');

        expect(array_column($cards['cards'], 'code'))->toBe(['empty_title', 'missing_page'])
            ->and($cards['cards'][0]['title'])->toBe('1 pages have no title')
            ->and($matrix['totals'])->toMatchArray(['demand_real' => 100, 'demand_raw' => 300, 'ratio' => 3.0])
            ->and((string) $csv->getContent())->toContain("'=HYPERLINK")
            ->and($csv->headers->get('Content-Disposition'))->toContain('attachment')
            ->and(substr((string) $xlsx->getContent(), 0, 2))->toBe('PK');
    })->skip(fn () => !class_exists(ZipArchive::class), 'ext-zip is missing.');
});

describe('MCP operations', function () {
    it('answer as the session manager, with the page’s permissions', function () {
        adfsUser(5, ['adataforseo']);
        adfsProject();

        expect(Operations::matrix(Actor::for(5)))->toHaveKeys(['targets', 'totals'])
            ->and(fn () => Operations::matrix(Actor::for(99)))->toThrow(WorkbenchException::class);
    });

    it('apply fixes through the same checks as the page', function () {
        adfsUser(5, ['adataforseo']);
        $project = adfsProject();
        $doc = adfsDocument('x');
        $issue = Issue::query()->create(['project_id' => $project->id, 'source' => 'audit', 'code' => 'empty_title', 'severity' => 'high', 'url' => 'https://example.test/x', 'url_hash' => 'b', 'document_id' => $doc, 'fix' => 'seo_fields', 'details' => ['suggest' => ['meta_title' => 'T']], 'status' => 'open']);
        \Elcreator\aDataForSeo\Integrations\SSeo::fake(new AdfsFakeSeo());

        expect(fn () => Operations::applyFix(Actor::for(5), ['issue' => $issue->id]))->toThrow(WorkbenchException::class);
    });
});

describe('manager 3.5 and 3.6+', function () {
    afterEach(function () {
        \Elcreator\aDataForSeo\Support\Manager::$svg = null;
        adfsRemoveTree(ADFS_TEST_ROOT . '/manager');
    });

    function adfsMediaFile(string $path): void
    {
        $file = \Elcreator\aDataForSeo\Support\Manager::mediaPath($path);
        @mkdir(dirname($file), 0775, true);
        file_put_contents($file, '/* test */');
    }

    it('uses Font Awesome on 3.5 and Tabler evo-icon classes on 3.6+', function () {
        expect(\Elcreator\aDataForSeo\Support\Manager::icon('search', 'search'))->toBe('fa fa-search')
            ->and(\Elcreator\aDataForSeo\Support\Manager::fontAwesomeUrl())->toBeNull();

        adfsMediaFile('style/common/font-awesome/css/font-awesome.min.css');
        expect(\Elcreator\aDataForSeo\Support\Manager::fontAwesomeUrl())->toBe('/manager/media/style/common/font-awesome/css/font-awesome.min.css');

        adfsMediaFile('style/common/icons/manager-icons.css');
        expect(\Elcreator\aDataForSeo\Support\Manager::icon('search', 'search'))->toBe('evo-icon evo-icon-search');
    });

    it('hands the page Tabler SVGs for every icon it uses when the set is installed', function () {
        expect(\Elcreator\aDataForSeo\Support\Manager::svgIcons())->toBe([]);

        \Elcreator\aDataForSeo\Support\Manager::$svg = static fn (string $name) => $name === 'rocket' ? 'not svg' : '<svg data-name="' . $name . '"></svg>';
        $icons = \Elcreator\aDataForSeo\Support\Manager::svgIcons();

        expect($icons['tasks'])->toBe('<svg data-name="list-check" xmlns="http://www.w3.org/2000/svg"></svg>')
            ->and($icons)->not->toHaveKey('rocket')
            ->and(count($icons))->toBe(count(\Elcreator\aDataForSeo\Support\Manager::ICONS) - 1);

        // Every icon name the script asks for has a Tabler counterpart.
        preg_match_all("/(?:icon\\(|icon: )'([a-z0-9-]+)'/", implode("\n", adfsSources('views')), $m);
        expect(array_values(array_diff(array_unique($m[1]), array_keys(\Elcreator\aDataForSeo\Support\Manager::ICONS))))->toBe([]);
    });

    it('cleans blade-icons markup into valid XML: one value per attribute, the icon’s own', function () {
        $raw = "<svg width=\"20\" height=\"20\" stroke-width=\"1.5\" fill=\"none\" stroke=\"currentColor\" class=\"inline-block\"\n  xmlns=\"http://www.w3.org/2000/svg\"\n  width=\"24\"\n  height=\"24\"\n  viewBox=\"0 0 24 24\"\n  fill=\"none\"\n  stroke=\"currentColor\"\n  stroke-width=\"2\"\n  class=\"icon icon-tabler\"\n><path d=\"M6 21l15 -15\" /></svg>";
        $clean = \Elcreator\aDataForSeo\Support\Manager::cleanSvg($raw);

        $xml = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $parsed = $xml->loadXML($clean);
        libxml_use_internal_errors($previous);

        expect($parsed)->toBeTrue()
            ->and($xml->documentElement->getAttribute('stroke-width'))->toBe('2')
            ->and($xml->documentElement->hasAttribute('width'))->toBeFalse()
            ->and($xml->documentElement->hasAttribute('class'))->toBeFalse()
            ->and($xml->documentElement->getAttribute('viewBox'))->toBe('0 0 24 24')
            ->and($clean)->toContain('<path d="M6 21l15 -15" />')
            ->and(\Elcreator\aDataForSeo\Support\Manager::cleanSvg('<div></div>'))->toBeNull();

        // svgIcons() hands out only cleaned markup.
        \Elcreator\aDataForSeo\Support\Manager::$svg = static fn () => $raw;
        expect(substr_count(\Elcreator\aDataForSeo\Support\Manager::svgIcons()['magic'], 'stroke-width='))->toBe(1);
    });

    it('renders the standalone page without evo-ui and evo-ui’s shell with it', function () {
        $data = \Elcreator\aDataForSeo\Http\Controllers\PageController::viewData('/m', 'tok');
        $page = (string) file_get_contents(dirname(__DIR__, 2) . '/views/page.blade.php');

        $js = implode("\n", adfsSources('views'));
        expect($data['sseoUrl'])->toBe('https://github.com/Seiger/sSeo')
            ->and($js)->toMatch("/function sseoMissing[\\s\\S]{0,300}href: CFG\\.sseoUrl, target: '_blank', rel: 'noopener noreferrer'/")
            ->and($js)->not->toContain("text: t('ui_sseo_missing')");

        expect($data['evoTheme'])->toBeNull()
            ->and($data)->toHaveKeys(['baseUrl', 'csrfToken', 'signupUrl', 'icons', 'iconsUrl'])
            ->and($page)->toContain("@include('evo::partials.assets')")
            ->and($page)->toContain('data-evo-ui-root')
            ->and($page)->toContain('@elseif($iconsUrl)');
    });

    it('takes every colour from tokens that follow evo-ui’s theme', function () {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/views/partials/styles.blade.php');
        $rules = substr($css, strpos($css, '* { box-sizing'));

        expect($css)->toContain('.sx-evo {')->and($css)->toContain('var(--color-base-100')
            ->and(preg_match_all('/#[0-9a-f]{3,6}\b/i', $rules))->toBe(0);
    });
});

describe('dashboard', function () {
    it('escapes CMS tags in the widget', function () {
        expect(DashboardWidget::escape('[[snippet]] {{chunk}} <b>'))->not->toContain('[[')
            ->and(DashboardWidget::escape('[[snippet]] {{chunk}} <b>'))->not->toContain('{{')
            ->and(DashboardWidget::escape('<b>'))->toBe('&lt;b&gt;');
    });

    it('returns the widgets serialised with ours added, for a manager who may use the module', function () {
        adfsUser(5, ['adataforseo']);
        AdfsTestCore::$loginUserId = 5;
        adfsProject();

        $widgets = unserialize(DashboardWidget::handle(['widgets' => ['news' => ['id' => 'news']]]));

        expect($widgets)->toHaveKeys(['news', 'adataforseo'])->and($widgets['adataforseo']['body'])->toContain('<table');
    });
});

describe('translations', function () {
    function adfsEnglish(): array
    {
        return require dirname(__DIR__, 2) . '/lang/en/global.php';
    }

    it('has every key the code names', function () {
        $english = adfsEnglish();
        $missing = [];
        $root = dirname(__DIR__, 2);

        foreach (['src', 'views', 'database'] as $folder) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $folder, FilesystemIterator::SKIP_DOTS)) as $file) {
                $code = (string) preg_replace(['~/\*.*?\*/~s', '~\{\{--.*?--\}\}~s', '~^\s*(//|\*).*$~m'], '', (string) file_get_contents($file->getPathname()));
                preg_match_all('/aDataForSeo::global\.([a-z][a-z0-9_]*)/', $code, $m);
                // A key followed by `)` or `,`; `t('prefix_' + n)` is built at run time.
                preg_match_all("/\\bt\\('([a-z][a-z0-9_]*)'\\s*[,)]/", $code, $js);

                foreach (array_merge($m[1], $js[1]) as $key) {
                    if (!str_ends_with($key, '_') && !array_key_exists($key, $english)) {
                        $missing[] = $key . ' (' . basename($file->getPathname()) . ')';
                    }
                }
            }
        }

        expect(array_values(array_unique($missing)))->toBe([]);
    });

    it('has the keys that are built at run time', function () {
        $english = adfsEnglish();
        $keys = [];

        foreach (['coverage', 'audit', 'research', 'difficulty', 'track', 'competitors', 'custom'] as $kind) {
            $keys[] = 'kind_' . $kind;
            $keys[] = 'ui_kind_help_' . $kind;
        }

        foreach (['critical', 'high', 'medium', 'low'] as $s) {
            $keys[] = 'sev_' . $s;
        }

        foreach (['easy', 'medium', 'hard'] as $e) {
            $keys[] = 'effort_' . $e;
        }

        foreach (['job_cap', 'monthly_cap', 'balance', 'approval_needed'] as $r) {
            $keys[] = 'refusal_' . $r;
        }

        foreach (['not_indexed', 'indexed_not_in_sitemap', 'sitemap_missing', 'nothing_indexed', 'crawl_blocked', 'broken_page', 'server_error', 'empty_title', 'title_too_long', 'empty_description', 'no_h1', 'bad_canonical', 'orphan_page', 'internal_redirect', 'duplicate_title', 'duplicate_description', 'non_indexable', 'redirect_chain', 'redirect_loop', 'broken_link', 'position_drop', 'competitor_page'] as $code) {
            array_push($keys, 'code_' . $code, 'card_' . $code . '_title', 'card_' . $code . '_why');
        }

        foreach (['draft', 'awaiting_approval', 'queued', 'running', 'waiting', 'done', 'failed', 'cancelled', 'pending', 'posting', 'skipped', 'open', 'applied', 'ignored', 'resolved'] as $status) {
            $keys[] = 'status_' . $status;
        }

        foreach (['error_kind', 'error_no_targets', 'error_endpoint', 'error_payload', 'error_fix_unknown', 'error_fix_no_document', 'error_fix_nothing', 'error_redirect_root', 'error_explorer_queued', 'error_job_running', 'error_job_state', 'error_sseo_missing', 'error_undo_changed', 'error_redirect_exists', 'error_undo_draft_edited', 'error_draft_exists', 'card_missing_page_why', 'card_needs_locale_why', 'manual_generic', 'manual_target'] as $key) {
            $keys[] = $key;
        }

        expect(array_values(array_diff($keys, array_keys($english))))->toBe([]);
    });

    it('has no empty strings and well-formed placeholders', function () {
        foreach (adfsEnglish() as $key => $value) {
            expect(trim((string) $value))->not->toBe('', $key);
            preg_match_all('/(?<![\w:\/]):([A-Za-z_]\w*)/', (string) $value, $m);

            foreach ($m[1] as $name) {
                expect($name)->toMatch('/^[a-z][a-z0-9_]*[a-z0-9]$|^[a-z]$/', $key);
            }
        }
    });

    it('falls back to the English file outside a request', function () {
        expect(Lang::get('aDataForSeo::global.error_not_found', ['what' => 'job']))->toBe('The job was not found.')
            ->and(Lang::get('aDataForSeo::global.no_such_key'))->toBe('aDataForSeo::global.no_such_key');
    });
});

describe('conventions', function () {
    function adfsSources(string $folder = 'src'): array
    {
        $out = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/' . $folder, FilesystemIterator::SKIP_DOTS)) as $file) {
            $out[$file->getPathname()] = (string) file_get_contents($file->getPathname());
        }

        return $out;
    }

    it('never calls response(), never uses $modx, never has a BEL in a class name', function () {
        foreach (adfsSources() as $path => $code) {
            $code = (string) preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', $code);
            expect($code)->not->toMatch('/(?<![\w>:$])response\(/', $path)
                ->and($code)->not->toContain('$modx', $path)
                ->and($code)->not->toContain("\x07", $path);
        }
    });

    it('writes page text only as text nodes and shows the sign-up as a disclosed ad link in a new tab', function () {
        $js = implode("\n", adfsSources('views'));

        expect($js)->not->toContain('innerHTML')
            ->and($js)->toMatch('/href:\s*CFG\.signup,\s*target:\s*\'_blank\',\s*rel:\s*\'sponsored noopener noreferrer\'/')
            ->and($js)->toContain("t('ui_try_free')")
            ->and($js)->toContain("t('ui_ad')")
            ->and($js)->toMatch("/t\\('ui_need_login'\\)[\\s\\S]{0,200}t\\('ui_try_free'\\)/")
            ->and($js)->toContain("t('ui_affiliate_disclosure')");
    });

    it('keeps the layout steady between tabs and every tab reachable', function () {
        $views = dirname(__DIR__, 2) . '/views/partials/';
        $css = (string) file_get_contents($views . 'styles.blade.php');
        $core = (string) file_get_contents($views . 'script-core.blade.php');

        expect($css)->toContain('scrollbar-gutter: stable')
            ->and($css)->toMatch('/\.sx-tabs \{[^}]*flex-wrap: wrap/')
            ->and($css)->not->toMatch('/\.sx-tabs \{[^}]*overflow-x/')
            // Issues (with undo) is pushed outside the professional-only blocks.
            ->and($core)->toContain("list.push(['issues'")
            // add() returns the parent; holding its result as a placeholder emptied whole tabs when it was cleared.
            ->and(implode("\n", adfsSources('views')))->not->toMatch('/=\s*add\(/');
    });

    it('has an Account tab, opened from the header badge, that stops offering demo data after a real login', function () {
        $views = dirname(__DIR__, 2) . '/views/partials/';
        $core = (string) file_get_contents($views . 'script-core.blade.php');
        $settings = (string) file_get_contents($views . 'script-settings.blade.php');
        $home = (string) file_get_contents($views . 'script-home.blade.php');

        expect($core)->toContain("list.push(['account'")
            ->and($core)->toMatch("/var openAccount = function[\\s\\S]{0,200}go\\('account'\\)/")
            ->and($core)->toMatch("/cls: 'sx-open-account'[^}]*onclick: openAccount/")
            // A red dot on the Account button and tab while on demo data.
            ->and($core)->toMatch("/function accountNeedsAttention[\\s\\S]{0,200}return !!key\\.present && key\\.env === 'demo'/")
            // The data-source label is a button that opens Account as well.
            ->and($core)->toMatch("/h\\('button', \\{ type: 'button', 'class': 'sx-badge sx-badge-link[^}]*onclick: openAccount \\}\\)/")
            ->and($core)->toContain('add(accountBtn, attentionDot())')
            ->and($core)->toContain("tab[0] === 'account' ? attentionDot() : null")
            // The data-source label comes after the Account button.
            ->and($core)->toMatch("/sx-account' \\}, \\[\\s*accountBtn,\\s*h\\('button'/")
            ->and($settings)->toContain('R.account = function')
            ->and($settings)->toMatch("/function envChoices[\\s\\S]{0,400}if \\(!signedIn\\(key\\)\\) \\{ list\\.push\\(\\['demo'/")
            ->and($settings)->toContain('radios(t(\'field_env\'), envChoices(key)')
            ->and($home)->toMatch("/c\\[0\\] !== 'demo' \\|\\| !signedIn\\(S\\.boot\\.key\\)/");

        // Choices are radio groups (fieldset + legend), not dropdowns; demo needs no login fields.
        expect($settings)->not->toContain('select(')
            ->and($settings)->toContain("radios(t('field_env')")
            ->and($settings)->toContain("radios(t('field_scope')")
            ->and($settings)->toContain("radios(t('field_mode')")
            ->and($settings)->toContain("credentials.hidden = env.value === 'demo'")
            // Choosing a mode saves it at once and repaints only the tabs.
            ->and($settings)->toMatch("/mode\\.addEventListener\\('change'[\\s\\S]{0,300}api\\('POST', '\\/project', \\{ mode: chosen \\}\\)[\\s\\S]{0,400}paintTabs\\(\\)/")
            ->and($core)->toMatch("/function radios[\\s\\S]{0,1200}h\\('fieldset'[\\s\\S]{0,200}h\\('legend'/");

        // The key form lives only in the Account tab now.
        $settingsTab = substr($settings, strpos($settings, 'R.settings = function'));
        expect($settingsTab)->not->toContain("t('ui_key_title')");
    });

    it('puts every POST route behind the module group (CSRF) and only GET elsewhere', function () {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/routes.php');

        expect($routes)->not->toContain('Route::delete')->and($routes)->not->toContain('Route::put')->and($routes)->not->toContain('withoutMiddleware');
    });

    it('encrypts keys at rest and reads them back', function () {
        $sealed = Crypt::encrypt('hunter2');

        expect($sealed)->toStartWith('adfs1:')->and($sealed)->not->toContain('hunter2')->and(Crypt::decrypt($sealed))->toBe('hunter2');
    });
});
