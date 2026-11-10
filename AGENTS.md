# AGENTS.md - aDataForSeo

Context file for AI coding agents. Package `elcreator/adataforseo`, namespace `Elcreator\aDataForSeo\` -> `src/`,
PHP 8.3+, an **Evolution CMS 3.5+** plugin (`evolutioncms-plugin`), GPL-3.0-or-later. `docs/DESIGN.md` is the
authoritative contract (hard rules, data model, service APIs, build waves); `README.md` is the user-facing view.
Read both before changing behaviour.

## What it is

Gets the site it is installed on found in search. It reads DataForSEO (index coverage, OnPage audit, keyword demand,
SERPs, rankings) and turns it into ranked, concrete changes to this site, each applied with one click: sSeo fields,
redirects, draft documents. Beginners get a wizard and plain-language cards; professionals get the page matrix, raw
data, an API explorer, exports and MCP tools. Work runs in the background on the CMS task queue or sTask.

## Status

**Built** (2026-11-10): Waves 1–4, MCP tools and the template Writer of Wave 5. Not yet: the ai.artur.work gateway
transport and `gateway` Writer driver, local pack, AI visibility, backlinks. The offline `demo` env
(`Api\DemoTransport`) answers in DataForSEO's real shapes and drives the end-to-end tests and `adfs:demo`.
`docs/DESIGN.md` lists the files each wave owns.

## Planned layout

```
config/aDataForSeo.php       merged under cms.settings.aDataForSeo; config/lexicon/<vertical>.php (stems, slug templates)
database/migrations/         adfs_projects|keys|jobs|steps|calls|keywords|targets|rankings|issues|changes;
                             permissions adataforseo, adataforseo_spend, adataforseo_manage
lang/en/global.php           the only locale edited by hand; other locales are produced by a translator afterwards
views/                       module page + partials (assets inlined, no publish step)
src/aDataForSeoServiceProvider  register(): config + task type. boot(): migrations/lang/views, commands, scheduler,
                             listeners, MCP, module. Front end: pingback route only (when enabled), nothing else
src/Api/                     Client, Response, Pricing, Estimator, Estimate, Ledger, Cache, ApiException, Credentials
src/Queue/                   Queue, Dispatcher, SystemQueue, STaskQueue  (aMount's classes, Run -> Job)
src/Engine/                  Slice, Steps/{Post,Wait,Fetch,Live,Analyse}, Plans/<Kind>Plan
src/Analysis/                IndexCoverage, Audit, Research/{Universe,Classifier,Clusters,Targets,Difficulty,Priority},
                             Tracking, Competitors
src/Fixes/                   SeoFields, Redirect, DraftDocument, Manual
src/Integrations/            SSeo (soft dependency), SLang/SMultisite helpers
src/Services/                Jobs, Projects, Keys, Actor, WorkbenchException
src/Support/                 Config, Crypt, Lang, Principal, Keys, UrlMap
src/Console/                 JobHandler (task handler), TickCommand (adfs:tick), DemoCommand (sandbox)
src/Workers/JobWorker        sTask worker (file guarded: only loads when sTask exists)
src/Listeners/               DashboardWidget (OnManagerWelcomeHome), DocumentPanel (OnDocFormRender), UrlMap invalidation
src/Mcp/                     optional eMCP tools (soft dependency)
tests/                       Pest, in-memory SQLite built by the package's real migrations
ci/ .github/                 full CMS with the plugin inside (Docker), smoke tests, zip — copy aMount's
```

## DataForSEO facts and traps (measured, not assumed)

- **Prices come from `appendix/user_data`** (free): `result.price.<api>.<function>.priority_<low|normal|high>` is a
  list of `{cost_type: per_request|per_result, cost}`. The same answer holds `money.balance`, `rates`,
  `backlinks_subscription_expiry_date` and `llm_mentions_subscription_expiry_date`. Do not hardcode prices; the
  appendix of `docs/DESIGN.md` is a snapshot for tests and sanity checks only.
- **Every response carries `cost`** (top level). It is the truth for the ledger; estimates are for gating only.
  **Except `task_get`**: it echoes the price of the task already charged at `task_post` (verified against the account
  balance: 283 SERP fetches reported $0.17 that was never charged again). Record `task_get` as 0, or every queued task
  is counted twice. Reconcile the ledger with `user_data` → `money.balance` periodically.
- **Task-level status codes**: `20000` ok, `20100` task created. The HTTP status can be 200 while a task failed;
  always read `tasks[i].status_code`.
- **Live vs queue**: Labs is live-only. SERP, OnPage and Keywords Data have `task_post` → `tasks_ready` → `task_get`
  (the latter two free). SERP standard queue is $0.0006 vs $0.002 live (depth 10); `priority: 2` doubles the queue
  price. Default to the queue; live only for one-off UI requests.
- **Labs covers ~90 countries and only their local language** (Czechia: `cs`, Poland: `pl`, Slovakia: `sk`; Ukraine:
  `uk` and `ru`; Russia and Belarus are not supported at all). Ask `dataforseo_labs/locations_and_languages` (free).
  For any other language use Keywords Data (Google Ads), which takes any keyword for a location.
- **Labs `keyword_difficulty` is unusable for priority**: `null` for keywords outside Labs' language (all Cyrillic
  queries in CZ/PL/SK), and 0–10 for city queries whose top 10 are national portals. Compute difficulty from the
  SERP (`Analysis\Research\Difficulty`). `null` means unknown everywhere, never 0.
- **Google Ads buckets close variants**: different spellings report the same `search_volume` and the same
  `monthly_searches` vector. Summing them inflates demand (×7.6 on one real page). Deduplicate by
  (volume, 12-month vector) and by Labs `keyword_properties.core_keyword`. A uk and an ru spelling can share a bucket
  ("купити/купить квартиру варшава").
- **SERP organic `url` and `domain` are `google.com/goto?url=...` wrappers** (seen in `organic/live/regular` and
  `task_get/regular`, November 2026). The real host is in `breadcrumb` (`https://www.sreality.cz › ...`). Items with
  no host and a follower count in `breadcrumb` are social posts/groups. Check `advanced` before relying on its fields.
- **A new site has no `ranked_keywords`** (empty result, not an error). Show demand and opportunities instead of
  zeros.
- **`site:<domain>` through SERP is the cheapest health check** and was the most important finding on the reference
  site (0 of 65 landing pages indexed). `depth` above 10 costs more; estimate from the ledger's history.
- **Ads `search_volume`** costs the same for 1 or 1000 keywords: batch. `keywords_for_keywords` takes ≤ 20 seeds.
- **Sandbox** `https://sandbox.dataforseo.com/v3/` is free for registered users, returns dummy data in the real shapes,
  covers SERP, Keywords Data, Labs, OnPage, Backlinks and more. Use it for the demo mode, CI and tests.
- **`tag`** (≤ 255 chars) comes back in `data.tag`: use `<job uuid>:<step id>` so a crashed worker can find tasks it
  already paid for. `pingback_url`/`postback_url` accept `$id` and `$tag` placeholders; we only use pingback.
- **Backlinks and LLM Mentions can depend on a subscription**: read `backlinks_subscription_expiry_date` and
  `llm_mentions_subscription_expiry_date` from `user_data` and hide those features unless the date is in the future.
  Do not assume either way: the reference account (November 2026) reported `2050-01-01` for both, i.e. available.
- **There is no content generation API** (the old Content Generation API is absent from the price table). The AI
  part of DataForSEO measures (`ai_optimization`: LLM Responses, LLM Scraper, LLM Mentions, AI Keyword Data; SERP
  `ai_overview` items); text generation belongs to the ai.artur.work gateway (`docs/DESIGN.md`, Wave 5 `Writer`).

## Evolution CMS and sibling-package facts

- **Task registry**: `EvolutionCMS\Services\SystemTasks\SystemTaskRegistry` exists on 3.5.x and 3.6.x. Register
  `adataforseo.job` in `register()` (the consumer `system:task-worker` is a CLI process without routes), `concurrent`,
  guarded by `class_exists()` and `has()`. The handler implements `EvolutionCMS\Interfaces\SystemTaskHandlerInterface`
  and never writes `status` itself.
- **`system_cli_tasks` has no "not before" column** and `system:task-worker --once` runs every minute. A slice that
  only waits for DataForSEO must not queue a successor (a long-running worker would poll in a hot loop): set
  `job.wake_at`; `adfs:tick` dispatches due jobs. sTask's CLI worker honours `start_at`, so there the successor is
  created with `start_at = wake_at`.
- **sTask**: `createTask()` returns the active task when identifier/action/meta match — put the slice number in meta.
  Its run endpoint lets any manager create a task of any active worker, so `JobWorker::createTask()` checks
  permissions itself (precedent: `core/src/Workers/DatabaseBackup.php`). New worker records are inactive; activate
  them as aMount's `STaskQueue::ensureRegistered()` does. Worker classes are file-guarded so the core autoloads
  without sTask.
- **Scheduled work runs as a manager**: store `authorized_by` from the listener that saves the schedule, re-check
  that account on every run (`core/src/Services/BackgroundDatabaseBackup.php`). Posted values never set it.
- **sSeo** (`Seiger\sSeo`): write only through `sSeo::updateSeoFields(['resource_id', 'resource_type', <lang> =>
  [fields]])` with the changed fields only; it resolves sLang languages and the sMultisite `domain_key`. Read
  `before` values from `Seiger\sSeo\Models\sSeoModel` (`s_seo`: meta_title, meta_description, meta_keywords, robots,
  canonical_url, og_*, structured_data, exclude_from_sitemap, priority, changefreq). Redirects: `Seiger\sSeo\Models\sRedirect`
  (`s_redirects`: site_key, old_url, new_url, type). Sitemap: `sSeo::generateSitemap()`, read with
  `Seiger\sSeo\Support\Sitemaper::eachUrl()`. sSeo builds hreflang only from sLang; a site with its own language
  URLs has none unless OnPage proves otherwise. Soft dependency: without sSeo, these fixes are instructions only.
- **`registerRoutingModule()` refuses outside the backend**; its prefix is a hash of the module name: the page gets
  `baseUrl` from bootstrap, nothing hardcodes a module path.
- **CSRF**: the `mgr` middleware fails closed; every state change is a POST with `X-CSRF-TOKEN`. The pingback is
  the only public route and only wakes a job.
- **Never call `response()` in a controller** (needs a booted container; null in tests): use `new JsonResponse(...)`.
- **Translations outside a request**: `Support\Lang::get()` (aMount), not bare `__()`, in workers, commands and tests.
- **Dashboard widget output** goes through `escape_html_and_tags()`: the CMS parses `[[...]]`, `{{...}}` and `[(...)]`
  in dashboard placeholders.
- **Classmap-authoritative core**: a site installing this package gets its classes through Composer; never register
  namespaces at runtime.
- **Do not use `$modx`** — use `evo()`.

## Money rules in one place

Estimate before every paid call, refuse over budget, record the real `cost` from the response, cache by normalised
payload, ask for approval when the estimate is unknown or above the threshold, show the estimate before and the cost
after every action. Details: `docs/DESIGN.md`, hard rules 1–3.

## Tests

The suite will borrow an Evolution core's autoloader (no vendor dir here), as aMount's does:

```
EVO_CORE_PATH_TEST=<core with dev deps> php $EVO_CORE_PATH_TEST/vendor/pestphp/pest/bin/pest --no-coverage
```

Never run composer in the CMS checkout. `Api\Client` takes a transport seam; fixtures are real captured responses with
personal data removed (prices from `user_data`, Ads 12-month vectors, SERP `breadcrumb`). CI and integration use the
sandbox. Required suites are listed in `docs/DESIGN.md` → *Tests*.

## Conventions

English everywhere in code; docblocks say *why*; no commented-out code; user-visible strings go through
`aDataForSeo::global.*` and only `lang/en/global.php` is edited during implementation. Never write a class name
containing `\a` (as in `Elcreator\aDataForSeo`) through a double-quoted shell or Python string — it becomes a BEL
character; use the editor tools. Commits follow the CMS convention `type(scope): description`.
