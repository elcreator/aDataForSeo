# aDataForSeo — design and contracts

Package `elcreator/adataforseo`, namespace `Elcreator\aDataForSeo\` → `src/`, PHP 8.3+, an **Evolution CMS 3.5+**
plugin (`evolutioncms-plugin`), GPL-3.0-or-later. Read this before touching code: parallel authors rely on the
contracts below. Waves 1–4 and the Wave 5 MCP tools and template Writer are implemented (2026-11-10), the `gateway`
Writer driver on 2026-11-27; this file
remains the specification they are held to.

Reference implementations to imitate (same author, same conventions — read, do not edit):
- `C:\projects\Opensource\evo\aMount` — **the queue layer to reuse** (`src/Queue/*`, `Workers/SyncWorker`,
  `Console/TickCommand`), `Support\Principal`/`Crypt`/`Lang`, controllers, `DemoCommand`, `ci/`, tests.
- `C:\projects\Opensource\evo\aIMage` — slicing (`Console\BatchHandler`, `Support\JobQueue`), async steps parked in
  `polling`, `Gateway\Estimator`/`Estimate` (null = unknown, never free), approval gate, `ApiKeys`, Connect.
- `C:\projects\Opensource\evo\sSeo` — where fixes land (`sSeo::updateSeoFields()`, `sRedirect`, `Sitemaper`).
- `C:\projects\Opensource\evo\sTask` — the alternative queue (docs/en/04-development).
- CMS sources: `C:\projects\Opensource\evo\evolution` (its `AGENTS.md`: CSRF rules, task registry,
  `core/src/Workers/DatabaseBackup.php` + `Services/BackgroundDatabaseBackup.php` for scheduled work run as a manager).

## What it does

Gets **this** site found in search. The plugin turns DataForSEO data into a short list of concrete changes to this
site, ranked by expected effect, each with a button that applies it (sSeo fields, a redirect, a draft document) or a
plain explanation of what to do by hand. Raw data is a means, not the product.

Two audiences, one module:

- **Beginner** — a wizard (key or free demo → "where are your customers?" → budget) runs an audit by itself and
  answers with "do this now" cards in plain language. No KD numbers, no empty charts, no jargon without a tooltip.
- **Professional** — the same cards plus the page matrix with editable weights, the keyword and SERP data behind
  every card, an API explorer with a price preview, exports (CSV/XLSX), queue priority, and MCP tools for agents.

What a site gets, in the order it is built:

1. **Index coverage** — pages in the sitemap vs pages Google returns for `site:`; pages that are not indexed.
2. **Technical audit** — OnPage crawl: duplicate/empty titles and descriptions, 404s, redirect chains, canonical,
   robots, hreflang, orphan pages; each issue mapped to a document and to a fix.
3. **Demand and the page matrix** — what people search for in this site's markets and languages, deduplicated,
   mapped to existing documents or to pages worth creating, with a priority.
4. **Rank tracking** — weekly positions of each document's target queries, history, a dashboard widget.
5. Later: competitors and gaps, local pack, AI Overview / LLM visibility, backlinks (paid subscription only).

## Hard rules (security / correctness / money)

1. **No call without a price.** Every paid call goes through `Api\Client::call()`, which asks `Api\Estimator` first and
   refuses when the estimate breaks the job cap, the project's monthly cap or the account balance. An estimate that
   cannot be computed is `null` (unknown) and **always** requires approval; it is never treated as zero.
2. **The response's `cost` is the truth.** It is written to the ledger (`adfs_calls`) for every call, cached or not,
   before the response is used. Budgets are checked against the ledger, not against estimates. **`task_get` answers
   repeat the cost of the task charged at `task_post`; they are recorded as 0**, except that a SERP shorter than its
   `depth` reports a lower final cost there: the difference is DataForSEO's refund and is booked once as a negative
   row on the posting step. An OnPage crawl below `max_crawl_pages` is refunded the same way, booked from the
   summary as posted × crawled / max. A SERP keyword with a search operator (`site:` ...) costs five base prices per 10 results. `adfs:tick` reconciles the ledger with
   `user_data` → `money.balance` daily and logs a drift above `budget.drift_alert`.
3. **Approval gate.** A job whose estimate exceeds `budget.approve_above` (or is unknown) stops in
   `awaiting_approval`; only a manager with `adataforseo_spend` approves it. Beginners default to a low threshold.
4. **Secrets**: the DataForSEO login and password are encrypted at rest (`Support\Crypt`, AES-256-GCM, as aMount/aIMage),
   masked in every response, never logged, never put in task payloads (`system_cli_tasks.payload_json`,
   `s_tasks.meta`) — those carry ids only. A blank field on update keeps the stored value.
5. **Permissions** (migration adds them; role 1 holds all):
   - `adataforseo` — open the module, read reports and cached data;
   - `adataforseo_spend` — start or approve work that costs money, within the budgets;
   - `adataforseo_manage` — keys, budgets, schedules, project settings.
   Applying a fix additionally requires what the CMS requires for that change: `save_document` and access to the
   document (document groups when `use_udperms` is on) for sSeo fields and drafts; for redirects, the sSeo module
   permission. The check is made **when the fix is applied**, for the manager applying it, never cached.
6. **Work queued or scheduled runs as a manager, re-checked now.** A job stores `user_id`; every slice re-reads that
   account (exists, not blocked, role holds `adataforseo_spend`) through `Support\Principal::forUser()` (DB, never
   `$_SESSION`). A schedule stores `authorized_by` = the manager who enabled it (written only by the listener that
   saves the schedule, never from posted input), as the core's `BackgroundDatabaseBackup::authorizeSchedule()` does;
   a scheduled job is refused and logged once when that manager lost the right.
7. **sTask's run endpoint lets any manager create a task of any active worker.** `Workers\JobWorker::createTask()`
   therefore checks the permission itself (as `core/src/Workers/DatabaseBackup.php` does) and refuses without it.
8. **Nothing is changed on the site without a manager's click**, except what a manager explicitly turned on as
   automatic (none by default). Every applied change is recorded with its previous value in `adfs_changes` and can be
  undone from the module or MCP while the field still holds the value we wrote.
9. **A step must be safe to run twice.** DataForSEO task ids are stored before the job advances; a fetch of an
   already-fetched task is a no-op; posting is keyed by `tag` = `<job uuid>:<step id>` so a worker that died between
   `task_post` and recording the ids finds its tasks with `tasks_ready`/the tag instead of paying again.
10. **CSRF**: every non-GET manager route carries `X-CSRF-TOKEN`/`_token`. The only public route is the pingback
    (rule 11).
11. **Pingback** (`queue.pingback`, off by default): `GET /adataforseo/ping/{token}?id=$id&tag=$tag`, where `token` =
    HMAC-SHA256(job uuid, site secret). It only sets the job's `wake_at = now` and dispatches a slice; it never reads
    results from the request and answers 204 to everything (no oracle). Results are always fetched by the worker.
12. Every refusal (budget, permission, approval, schedule) is written to the event log (source `aDataForSeo`), one line,
    request values escaped. A refusal that only returns a message is incomplete.
13. **Front-end cost.** On a front-end request the provider registers the pingback route (when enabled) and nothing
    else: no DB access, no config sync, no worker registration (aMount's rule).

## Architecture

```
 manager page / MCP / schedule
        │  Services\Jobs::start(kind, project, options)  → adfs_jobs (+ estimate, approval)
        ▼
 Queue\Dispatcher ── auto ──► STaskQueue (sTask installed & heartbeat fresh) ─► Workers\JobWorker
        │                └──► SystemQueue (system_cli_tasks, type adataforseo.job) ─► Console\JobHandler
        ▼
 Engine\Slice::run(job, budgetSeconds)     ≤ limits.slice_seconds, then returns
   ├─ Step kinds: post → wait → fetch → analyse → (apply only if a manager asked for automatic)
   ├─ Api\Client (prod / sandbox) ─► Api\Estimator ─► Api\Ledger ─► Api\Cache
   └─ wake_at in the future → no successor; `adfs:tick` (every minute) or the pingback wakes it
        ▼
 Analysis\*  (IndexCoverage, Audit, Universe → Clusters → Targets → Difficulty → Priority, Rankings)
        ▼
 adfs_issues / adfs_targets / adfs_rankings  ─► cards in the UI ─► Fixes\* ─► sSeo / documents / redirects
```

## Data model

All tables `adfs_*`, created by the package's migrations. JSON columns are arrays; nothing stores secrets in clear.

| Table | Holds |
|---|---|
| `adfs_projects` | one per site/domain_key: `domain`, `domain_key` (sMultisite), `markets` JSON (`[{country, location_code, languages[], geo:[{name, spellings{lang:[...]}}]}]`), `competitors` JSON, budgets (`monthly_cap`, `job_cap`, `approve_above`), `mode` (beginner/pro), `schedule` JSON, `authorized_by` |
| `adfs_keys` | `scope` (site/user), `user_id`, `login`, `password_enc`, `env` (prod/sandbox), `verified_at`, `balance`, `subscriptions` JSON |
| `adfs_jobs` | `uuid`, `project_id`, `user_id`, `kind` (audit/coverage/research/difficulty/track/competitors/custom), `status` (draft, awaiting_approval, queued, running, waiting, done, failed, cancelled), `phase`, `progress`, `estimate` (nullable decimal), `spent`, `wake_at`, `queue`, `task_id`, `options` JSON, `result` JSON, `error`, timestamps |
| `adfs_steps` | `job_id`, `kind` (post/wait/fetch/analyse), `endpoint`, `payload` JSON, `tag`, `dfs_task_ids` JSON, `status`, `attempts`, `estimate`, `cost`, `error` |
| `adfs_calls` | the ledger and cache index: `project_id`, `job_id`, `endpoint`, `cache_key` (sha1 of endpoint+normalised payload+env), `cost`, `status_code`, `items`, `response_path` (gz under `core/storage/adataforseo/`), `fetched_at`, `expires_at` |
| `adfs_keywords` | `project_id`, `market`, `lang`, `keyword`, `volume`, `monthly` JSON (12 values), `kd_labs` (nullable), `core_keyword`, `cpc`, `competition`, `intent`, `source` (labs/ads/ua…), `geo`, `deal`, `type`, `cluster_id`, `fetched_at` |
| `adfs_targets` | one target page = (country, geo, deal, type, lang): `document_id` (nullable), `resource_type`, `url`, `proposed_url`, `primary_keyword_id`, `demand_real`, `demand_ambiguous`, `demand_origin`, `demand_raw`, `kd_serp` (nullable), `native_share`, `score`, `tier`, `status` (exists/not_indexed/missing/needs_locale), `serp_snapshot` JSON |
| `adfs_rankings` | `target_id`/`keyword_id`, `date`, `position` (nullable = not in depth), `url`, `serp_features` JSON |
| `adfs_issues` | `project_id`, `job_id`, `code`, `severity`, `url`, `document_id`, `details` JSON, `fix` (code of a `Fixes\*` action or null), `status` (open/applied/ignored/resolved) |
| `adfs_changes` | `issue_id`/`target_id`, `user_id`, `target` (sseo/document/redirect), `resource_id`, `lang`, `domain_key`, `field`, `before`, `after`, `applied_at`, `undone_at` |

## To build (contracts)

### Wave 1A — API layer — `src/Api`, `src/Support`

- `Support\Config` — typed access to `cms.settings.aDataForSeo` (merged in `register()`), `queueDriver()`,
  `limit($key)`, `budget($key)`.
- `Support\Crypt`, `Support\Lang`, `Support\Principal` — copy the aMount classes, rename the namespace; no new
  behaviour. `Principal`: `mayUse() maySpend() mayManage() mayEditDocument(int $id)`.
- `Support\Keys` — `for(Principal): ?Credentials` (manager key → site key → env `DATAFORSEO_LOGIN`/`DATAFORSEO_PASSWORD`
  → none). `verify(Credentials): array` calls `appendix/user_data` (free) and stores balance, rates and the
  `backlinks_subscription_expiry_date` / `llm_mentions_subscription_expiry_date`.
- `Api\Client` — `__construct(Credentials $c, string $env = 'prod')`; base `https://api.dataforseo.com/v3` or
  `https://sandbox.dataforseo.com/v3` (free, dummy data, same shapes). `call(string $endpoint, ?array $tasks,
  Context $ctx): Response` = cache lookup → `Estimator::estimate()` → budget check (`Ledger::allow()`) → HTTP (Basic
  auth, gzip, `connect_timeout` 10, `timeout` 120) → `Ledger::record()` → cache write. `Response` exposes `tasks()`,
  `cost()`, `items($taskIndex)`. Task-level status: `20000` ok, `20100` task created; anything else is a per-task
  failure carried in the `Response`, not an exception.
- `Api\ApiException` — carries `retryable` (HTTP 5xx, timeouts, 429, task codes listed in `api.retryable_codes`);
  auth and validation failures are not retryable and fail the step with a translated message.
- `Api\Pricing` — the price table from `appendix/user_data` → `price` (per endpoint path and priority, entries
  `{cost_type: per_request|per_result, cost}`), cached `api.pricing_ttl` (24 h) with stale-on-failure.
  `Pricing::for(string $endpoint, int $priority): ?array`.
- `Api\Estimator` — `estimate(endpoint, tasks, priority): Estimate` from `Pricing`: per_request × tasks +
  per_result × expected items (`limit`, keywords count, depth). When the expected item count cannot be known in
  advance (e.g. `ranked_keywords` without `limit`, SERP `depth` > 10), the result is a range calibrated from the
  ledger's history for that endpoint and depth, or `null` when there is no history. Never `0` for unknown.
- `Api\Estimate` — value object `{amount: ?float, low: ?float, high: ?float, basis: string}`; `sum()`.
- `Api\Ledger` — `allow(Context, Estimate): ?string` (null = allowed, else refusal code: `job_cap`, `monthly_cap`,
  `balance`, `approval_needed`), `record(Context, endpoint, cost, …)`, `spent(projectId, from, to)`.
- `Api\Cache` — key = sha1(env + endpoint + normalised payload: keys sorted, keywords lower-cased and sorted,
  `tag` and callback URLs removed). TTL by endpoint family (`api.cache_ttl`): Labs and Keywords Data 30 d, SERP 7 d,
  OnPage per crawl, free appendix endpoints 1 d. A cached answer is free and is still logged in the ledger with
  `cost = 0` and `cached = true`.

### Wave 1B — queue and engine — `src/Queue`, `src/Engine`, `src/Workers`, `src/Console`

- `Queue\Queue`, `Queue\Dispatcher`, `Queue\SystemQueue`, `Queue\STaskQueue` — **aMount's classes with `Run`
  replaced by `Models\Job`.** Keep the contracts: `name() available() health() dispatch(Job)`; `auto` = sTask when
  installed and usable, else the CMS queue; `status()` for the UI with the hints (`system:task-worker`,
  `stask:worker`, the `schedule:run` cron line). If the queue layer is extracted into a shared package before this
  is built, depend on that instead (see *Open questions*).
- `Console\JobHandler implements EvolutionCMS\Interfaces\SystemTaskHandlerInterface`, `TYPE = 'adataforseo.job'`,
  registered in `register()` with `SystemTaskRegistry::register(TYPE, JobHandler::class, ['mode' =>
  MODE_CONCURRENT, 'parallelism' => Config::limit('parallelism', 2), 'permissions' => ['adataforseo_spend'], 'label'
  => 'aDataForSeo job'])`, guarded by `class_exists(SystemTaskRegistry::class)` and `has()`.
- `Workers\JobWorker extends Seiger\sTask\Workers\BaseWorker`, identifier `aDataForSeoJob`, action `make`, **file
  guarded** (`if (!class_exists(BaseWorker::class)) return;`). `createTask()` checks the permission (hard rule 7),
  puts `['job_uuid' => …, 'slice' => n]` in meta (the slice number defeats sTask's duplicate guard, which returns an
  active task with identical meta) and sets `started_by` = job user. `taskMake()` runs **one slice**, like the CMS
  handler.
- `Engine\Slice::run(Job $job, float $budgetSeconds, ?callable $report): SliceResult` — re-checks the job user
  (hard rule 6) and the job status (cancel is cooperative), then advances steps until the budget is spent or every
  remaining step waits. Returns `done|more|waiting|failed`. The caller queues a successor only for `more`.
  **Never sleeps.** `waiting` sets `job.wake_at` = earliest `next_check_at` of its waiting steps.
- Successors and waking:
  - CMS queue: `system_cli_tasks` has no "not before" column. A slice that ends in `waiting` queues **no** successor;
    `adfs:tick` (scheduled every minute in `afterResolving(Schedule::class)`, with the "already scheduled" guard)
    dispatches jobs whose `wake_at <= now`. This avoids a hot polling loop under a long-running `system:task-worker`.
  - sTask: the successor is created with `start_at = wake_at` (sTask's CLI worker honours `start_at`).
  - Pingback (hard rule 11) sets `wake_at = now` and dispatches.
- Step kinds (`Engine\Steps\*`):
  - `post` — `task_post` for queue-capable endpoints (SERP, OnPage, Keywords Data standard): up to 100 tasks per
    request, `priority` from the job (1 normal; 2 high costs more — pro only), `tag` = `<job uuid>:<step id>`,
    `pingback_url` when enabled. Stores the returned ids before anything else.
  - `wait` — `tasks_ready` (free) for the endpoint family; marks ready ids; next check backs off
    (`limits.poll_seconds`: 60, 120, 300, then every 300).
  - `fetch` — `task_get/<variant>/<id>` (free) for ready ids, writes the response through `Api\Cache`.
  - `live` — Labs and other live-only endpoints: one call per step.
  - `analyse` — pure PHP over fetched data (`Analysis\*`), idempotent, writes `adfs_*` rows.
- `Services\Jobs` — `estimate(kind, project, options): Estimate` (builds the step plan without calling anything paid),
  `start(...)`, `approve(Job, Principal)`, `cancel(Job)`, `retry(Job)`. Plans are built by `Engine\Plans\<Kind>Plan`.
- `Console\TickCommand` — `adfs:tick`: wake due jobs, reap stale ones (`heartbeat_at` older than `limits.stale_after`),
  create scheduled jobs (CMS-queue mode; with sTask the worker's own schedule does it), purge cache files past
  `expires_at` and ledger rows past `limits.history_days`.

### Wave 2 — coverage, audit and fixes — `src/Analysis`, `src/Fixes`, `src/Support/UrlMap`

- `Support\UrlMap` — URL ⇄ resource for the project: documents via `evo()->makeUrl()` (published, not deleted),
  sLang languages (`sLang::langConfig()`), sMultisite domain keys, sCommerce products when sSeo knows them
  (`resource_type`). `resolve(string $url): ?Resource{type, id, lang, domain_key}`; `urls(): iterable`. Built per
  project, cached, invalidated on `OnDocFormSave`/`OnDocPublished`/`OnDocUnPublished`.
- `Analysis\IndexCoverage` — plan: sitemap URLs (sSeo `Sitemaper::eachUrl()` when installed, else `/sitemap.xml`) +
  SERP `site:<domain>` (`depth` 100, more pages while results continue, capped by `limits.coverage_pages`). Output:
  indexed count, not-indexed URLs mapped through `UrlMap`, indexed URLs that are not in the sitemap (parameters, search
  pages — candidates for `noindex`). One `adfs_issues` row per finding. This is the beginner's first card.
- `Analysis\Audit` — OnPage `task_post` (`max_crawl_pages` from the budget; JS rendering only when the pro turns it
  on: it is 10× the price), then `summary`, `pages`, `duplicate_tags`, `non_indexable`, `redirect_chains`, `links`
  (all `task_get`-style, free after the crawl). Maps issues to resources through `UrlMap`. Warn when the crawl was
  blocked (a site that answers bots with a challenge returns almost nothing).
- `Fixes\*` — one class per fix code, `preview(Issue|Target, Principal): Change[]` and `apply(…): Change[]`:
  - `SeoFields` (title, description, robots, canonical, exclude_from_sitemap) → `Integrations\SSeo`;
  - `Redirect` (404 → suggested target) → `sRedirect`;
  - `DraftDocument` (a missing target page) → a new unpublished document under a parent the manager picks, alias from
    `proposed_url`, pagetitle/longtitle from the target, sSeo fields filled; never published automatically;
  - `Manual` — text instructions for what the plugin cannot do (server config, internal linking in templates).
- `Integrations\SSeo` — soft dependency: `available()` = `class_exists(\Seiger\sSeo\Facades\sSeo::class)`. Writes
  **only** through `sSeo::updateSeoFields(['resource_id' => id, 'resource_type' => type, <lang> => [changed fields
  only]])`; `domain_key` is resolved by sSeo (`resolveDocumentDomainKey()`). Reads current values from `sSeoModel`
  for `before` (hard rule 8). After creating documents, `sSeo::generateSitemap()`. Without sSeo, fixes of these kinds
  are shown as instructions and nothing is written.

### Wave 3 — demand and the page matrix — `src/Analysis/Research`

Learned on a real site (bazadomu.com, 2026-11-09); every rule here exists because the naive version was wrong.

- `Universe` — collects keywords per market and language:
  - local language: Labs `keyword_suggestions` for seeds, Labs `ranked_keywords` of competitors (top-10 positions,
    brand terms filtered), Labs `keywords_for_site` of the project domain once it ranks;
  - any other language, and anything Labs does not cover: Keywords Data Google Ads `search_volume` (≤ 1000 keywords
    per task, one price per task — batch them) and `keywords_for_keywords` (≤ 20 seeds) for ideas. Labs covers about
    90 countries and **only their local language** (Czechia: `cs` only) — check `dataforseo_labs/locations_and_languages`
    (free) per project, never assume;
  - origin demand (people abroad searching for this market, e.g. Ukraine for uk/ru pages about Poland): Labs
    `keyword_overview` in the origin location; reported separately, never added to `demand_real`.
- `Classifier` — keyword → `lang`, `geo`, `deal` (rent/sale/none), `type` (flat/house/land/room/garage/commercial/any),
  `brand`. Geo spellings come from the project's markets (`geo[].spellings`), seeded from SERP
  `locations/<country>` (free) for local and English names; other languages are added by the manager (or the optional
  Writer). Vocabulary per vertical lives in `config/lexicon/<vertical>.php` (real estate first); the design must not
  assume real estate beyond that file.
- `Clusters` — union-find inside one target page. Two keywords share a cluster when (a) Labs gives the same
  `core_keyword`, or (b) Google Ads reports the same volume **and** the same 12-month vector (volume ≥ 50, vector not
  constant) — Ads buckets close variants (13 spellings of "apartments for rent prague" each report 2400; real demand
  2400, naive sum 33,100), or (c) the same token bag (lower-case, diacritics removed, stop words removed, order
  ignored). One keyword per cluster counts. Different volumes or vectors are different demand and are summed.
- Language twins — a uk and an ru keyword with the same volume and vector (e.g. "купити/купить квартиру варшава")
  are one bucket: counted once, on the page whose locale the site has; the other page shows it as shared.
- Ambiguous intent — a keyword without a deal ("квартира варшава", "apartments in prague") is attached to the page
  users most likely want (rent for flats/rooms, sale for houses/land) and counted as `demand_ambiguous`, weighted.
- `Targets` — one row per (country, geo, deal, type, lang); existing documents matched through `UrlMap` (and, for a
  site without a clean URL scheme, by the manager in the UI); `proposed_url` from per-language slug templates
  (`config/lexicon/<vertical>.php`); `status` = exists / not_indexed / missing / needs_locale (the site has no such
  language yet).
- `Difficulty` — **Labs `keyword_difficulty` is not used for priority**: it is `null` for keywords outside Labs'
  language and was 0–10 for city queries whose top 10 were national portals. Instead: SERP top 10 of the primary
  keyword (standard queue), domain host from `breadcrumb` (see AGENTS.md: `url`/`domain` are `google.com/goto`
  wrappers), domain strength = `min(100, 100·log10(etv+1)/6)` from Labs `bulk_traffic_estimation` in that market
  (≤ 1000 targets per call), UGC results (Facebook groups, Telegram, YouTube, Reddit, Instagram, items without a URL
  that show follower counts) = 15, unknown = 10; a result whose title is not in the page's language counts at half
  strength for pages in a non-local language (a Polish portal answers a Russian query poorly — without this the
  uk/ru pages, the real opportunity on the reference site, ranked below unreachable local head terms);
  `kd_serp` = mean weighted by `1/log2(position+1)`. Labs measures domain traffic in the market's local-language
  database, so international sites are understated there: label en difficulty as optimistic.
  `native_share` = share of top-10 titles in the page's language (low share = Google lacks pages in that language).
  Only targets above `research.serp_check_min_demand` are checked; the rest use per-language defaults.
- `Priority` — `score = (real + w_amb·ambiguous + w_origin·origin) · (1 − kd/100)^k · (native_share < t ? bonus : 1)
  · (needs_locale ? locale_factor : 1)`; tiers P1/P2/P3 by rank. Every weight is a project setting with the
  defaults `w_amb 0.5, w_origin 0.5, bonus 1.25, t 0.5, locale_factor 0.8`, and `k` from the site's own strength:
  3 for a site with no rankings and links (with 1.5 a new site's P1 was all national head terms with KD 60–80),
  lowered towards 1.5 as `ranked_keywords` and backlinks of the project domain grow. A target whose demand was never
  measured is "not measured", never "no demand". The UI shows real demand, raw sum
  and their ratio side by side so the deduplication is visible.
- Export: XLSX (pages, keywords with their cluster and whether they count, parameters, method) and CSV.

### Wave 4 — tracking, competitors, dashboard — `src/Analysis/Tracking`, `src/Listeners`

- `Tracking` — weekly by default: SERP standard queue for each target's primary keyword (and up to N secondary),
  `depth` 20 (pro: up to 100, priced accordingly), project's markets/languages; `adfs_rankings` rows; deltas; the
  first run of a project is free of alerts.
- `Competitors` — Labs `serp_competitors` over the project's primary keywords, `domain_intersection` and
  `relevant_pages` for the top 3 → "pages they have that you don't" cards feeding `Targets`.
- `Listeners\DashboardWidget` (`OnManagerWelcomeHome`) — index coverage, open P1 cards, position changes, spend this
  month; escaped with `escape_html_and_tags()` (the CMS parses dashboard placeholders).
- `Listeners\DocumentPanel` (`OnDocFormRender`) — a "Search" block next to sSeo's tab: target query, demand,
  position, SERP top 10, suggested title/description with "apply to sSeo".

### Wave 5 — optional

- `Writer` — title/description suggestions: `template` driver (default, no AI: per-language patterns over the target's
  geo/deal/type and real data such as listing counts) and `gateway` driver (ai.artur.work feature 31,
  `docs/features/31-seo-writing.md`: `/v1/seo/meta`, `/schema`, `/media`, `/check`, `/translate`, later `/page`; the
  plugin sends the target's real data as `facts`, which the gateway requires and validates claims against; key
  handled like aIMage's `ApiKeys`; off by default). Suggestions are never applied without a click.
  **Built 2026-11-27 for titles and descriptions** (`Services\GatewayWriter`, `WriterController`, `POST /writer/quote`
  and `/writer/meta` with `{issue}` or `{target}`, `adataforseo_spend` and `save_document` on the page): project
  setting `settings.writer` = `template` (default) | `gateway`, chosen on the Settings tab; bootstrap flag
  `writer_ai` = on + may spend + a gateway key. The request carries the target's primary keyword and up to 10 of its
  keywords by volume, the page (longtitle/pagetitle, pagetitle as H1, the template description as summary, URL),
  the titles of published siblings (≤ 200) and `facts` = `siteName`, `place` (the market's spellings per language),
  `deal`/`propertyType` (lexicon words, only for a language the lexicon has) and `pagesInSection` (published
  children, ≥ 2). Demand, difficulty and positions never become facts. `/writer/meta` answers synchronously: quote,
  `approve_above` gate (the page shows the price first and confirms), `Ledger::allow` in **euros** whatever the
  project pays its analytics with, `Gateway\Client::write()` books `chargedEur` once per idempotency key, also from
  the error body of a `422 seo_unsupported_claim` / `seo_no_valid_variant`. The fix dialogs show "Suggest with AI"
  under the title/description inputs; "Use this" only fills the inputs. `@`-prefixed claim keys (the gateway's names
  for page data) are not shown as sources. Not built: `/schema`, `/media`, `/check`, `/translate`.
- Local businesses — wizard question "do customers visit you?" turns on SERP maps / local pack tracking and Business
  Data checks.
- AI visibility — `ai_overview` presence from fetched SERPs (free, already in the data) and the AI Optimization API
  (LLM mentions needs a subscription: shown only when `llm_mentions_subscription_expiry_date` is in the future).
- Backlinks — shown only when `backlinks_subscription_expiry_date` is in the future.
- MCP — `Mcp\ToolProvider` registered with eMCP when present (aIMage/aMount pattern): `adataforseo.coverage`,
  `.audit`, `.matrix`, `.serp`, `.rankings`, `.estimate`; mutating tools (`.apply_fix`, `.create_draft`,
  `.start_job`, `.undo_change`) carry eMCP's `WritesSite` marker and go through the same `Fixes\*` / `Jobs`
  services and permission checks. `.apply_fix` previews use the same `fields` and `new_url` overrides as applying;
  `.estimate` and `.start_job` accept the same `options`. Starting never approves a job: unknown or high estimates
  still wait for a manager's approval in the module.
  `.undo_change` also carries `DestructiveSite`, since undoing a draft deletes its document.

## UI

One routing module (`registerRoutingModule('aDataForSeo', routes, 'fa fa-magnifying-glass-chart')`), assets inlined
as in aIMage; icons from the manager's icon set, never emoji.

- **Wizard** (beginner, first run): key or "try with demo data" (sandbox) → markets and languages, prefilled from
  `site_url`, sLang, sMultisite → budget with a translated estimate ("enough for an audit and 3 months of tracking") →
  run coverage + audit.
- **Cards** (home): one card per finding, sorted by effect: what is wrong, why it matters, what it costs to fix,
  button. Difficulty as easy/medium/hard. Empty states explain what will appear and when.
- **Pro tabs**: Matrix, Keywords, Rankings, Issues, Jobs (estimate, spent, queue, progress), Ledger, API explorer
  (endpoint, payload, price preview, raw JSON), Settings (keys, budgets, schedules, weights, queue driver).
- Every money action shows the estimate before the click and the actual cost after.

## HTTP contract (JSON)

`{ok:true, ...}` or `{ok:false, error, message[, errors]}` with the refusal's HTTP status (403 forbidden, 404 not found,
409 refused — budget/approval/state, 422 invalid with `errors`, 502 DataForSEO failure). `GET /bootstrap` returns
`mode, project, markets, key {present, env, verified_at, balance, subscriptions}, budget {monthly_cap, spent_month,
job_cap, approve_above}, queue (Dispatcher::status()), flags {may_spend, may_manage, sseo, slang, smultisite,
stask}, csrf_token`. Controllers are thin (request → one service call → `JsonResponse`), never `response()`.

## Configuration (`config/aDataForSeo.php`, merged under `cms.settings.aDataForSeo`)

`enable`; `api.env` (prod/sandbox), `api.timeout`, `api.connect_timeout`, `api.pricing_ttl`, `api.cache_ttl[]`,
`api.retryable_codes[]`; `queue.driver` (auto/system/stask), `queue.pingback`; `limits.slice_seconds` (45),
`limits.parallelism` (2), `limits.poll_seconds[]`, `limits.stale_after`, `limits.coverage_pages`, `limits.history_days`;
`budget.monthly_cap` (5.00), `budget.job_cap` (2.00), `budget.approve_above` (0.50); `research.serp_check_min_demand`,
`research.weights[]`, `research.default_kd[lang group]`; `mcp.enabled`, `mcp.server`. Secrets come from the key store
or the environment, never from this file.

## Tests

Pest, in-memory SQLite built by the package's own migrations, an Evolution core's autoloader borrowed as in aMount
(`EVO_CORE_PATH_TEST`). `Api\Client` gets a transport seam; fixtures are real captured responses (prices from
`user_data`, Labs, Ads with 12-month vectors, SERP with `breadcrumb`) with personal data removed. Required suites:
Estimator (per_request/per_result, unknown → null, range from ledger), Ledger/budget refusals, Cache key
normalisation, Slice (budget, waiting → no successor, wake_at, cancel, run twice), both queue drivers, permission
re-checks for queued and scheduled jobs, Clusters (Ads buckets, core_keyword, token bags, uk/ru twins), Classifier,
Difficulty (breadcrumb parsing, UGC), Priority formula, Fixes (preview, apply, undo, permission at apply time),
translations (every `aDataForSeo::global.*` key used exists). Integration and CI runs use the sandbox (free).

## Gateway mode (built 2026-11-12)

A project pays one of two ways (`adfs_projects.billing`): `own` (DataForSEO credentials, US dollars, everything above) or
`gateway` (an ai.artur.work key, euros, analytics only). Chosen in the wizard, in Account ("How this site pays") or by
saving/connecting a gateway key (`GatewayController`, `use`). Both keys can be stored (`adfs_keys.provider`
`dataforseo|gateway`); `Support\Keys::gatewayFor()` / `saveGateway()` / `verifyGateway()` / `describeGateway()`; encrypted with
`Support\Crypt`, masked, never in payloads or logs. Getting a key, both ways as aIMage does: **Connect** (`GET
/gateway/connect` -> PKCE redirect to `{gateway.url}/connect`, `GET /gateway/connect/callback` -> `Gateway\Connect::exchange`)
and **pasting** a key (`POST /gateway/key`). `verify` = `GET /v1/account` + `GET /v1/seo/prices`.

- `Gateway\Client` (own transport seam `useTransport()`): `account() prices() quote(op, body) submit(op, body, idempotencyKey,
  Context) task(id) record(...)`. `submit` quotes first (`quote: true`), checks the caps through `Ledger::allow()`, then posts.
  `failure()` maps the `ApiError` envelope (`error.code` else `error.type`): 401/403 auth; 402 `insufficient_quota` -> refusal
  `balance`, `key_spend_cap_reached` -> refusal `key_cap`; 400 `seo_location_unsupported`; 404 `task_not_found`; 503/409/429/5xx
  retryable. `Gateway\Estimator::quote()` -> `Api\Estimate` (null quote = unknown = approval).
- Steps `gw_submit` (idempotency key `<job uuid>:<step id>`, written before the call; a stored 4xx refusal bumps a
  `key_generation` suffix so a balance refusal does not replay for 24 h) and `gw_wait` (`GET /seo/tasks/{id}`, `wake_at` backoff
  `gateway.poll_seconds`, books `chargedEur` once in the ledger, keeps the analytics under `jobs/<uuid>/`).
- Plans: `GatewayCoveragePlan` (sitemap read here + one `index-coverage` call, compared locally), `GatewayAuditPlan` (one
  `audit`), `GatewayDifficultyPlan` (targets that exist + job option `keywords`, grouped by market and language, chunks of
  100). Analysers `gw_coverage`, `gw_audit` (gateway codes mapped in `AuditResult::CODES`; unknown codes -> one
  `audit_other` manual card per page for codes it does not know; `title_too_short` and `canonical_missing` are
  one-click sSeo fixes, `duplicate_content` and `hreflang_*` manual cards; suggestions, documents and redirect targets made locally), `gw_difficulty`
  (`kd_serp`, `native_share`, `serp_snapshot.aggregates`, no hosts; unmatched searches kept in the job result).
- Currency: `adfs_jobs.currency` and `adfs_calls.currency` (existing rows USD). A job keeps the backend of the currency it was
  planned in; `Ledger`, budgets, `Projects::present`, the ledger report sum one currency at a time. Through the gateway the
  stored balance is not used for the cap check (the gateway answers 402 itself).
- `research`, `track`, `competitors`, `custom` and the API explorer are refused in gateway mode before anything is planned
  (`error_gateway_unsupported`, 409, event log); the UI explains instead of failing. MCP and schedules go through the same
  `Jobs` service, so they refuse alike.

## Open questions

1. **Shared queue package.** aMount has both drivers, aIMage only the CMS queue; a third copy here is a third place to
   fix the same bug. Extract `Queue\*` + tick into a small shared package first, or copy now and extract later?
2. **Billing through ai.artur.work. Resolved for analytics (2026-11-12, see "Gateway mode" below); research, tracking and competitors stay own-key.** Beginners without a DataForSEO account pay from the gateway balance (Connect,
   as aIMage). Specified on the gateway side as feature 30 (`c:\projects\ai-artur-work\docs\features\30-seo-data.md`,
   `/v1/seo/*`, planned). Design `Api\Client` with two transports from the start — DataForSEO directly (own key,
   professionals) and the gateway (Connect key, beginners) — behind the same Estimator/Ledger contract.
   **The gateway transport cannot sit at the `Api\Client` level**: the gateway returns analytics, never DataForSEO's
   raw responses, so it replaces whole plans (`coverage`, `audit`, `difficulty`, AI visibility → one `/v1/seo/*` call
   each, quoted with `quote: true`, recorded in the ledger in EUR), not single endpoint calls. `research`, `track`
   and `competitors` work on keyword and SERP records and stay own-key only until the gateway's distribution
   agreement (feature 30, owner decision 5).
   DataForSEO's terms (checked 2026-11-09: Terms of 12 June 2026, legal FAQ of 05.11.2026, Q3/Q6/Q10/Q13) shape both:
   with the site's **own** key the site owner is DataForSEO's client and may see, store and export everything; through
   the gateway, only processed analytics (coverage, issues, scores, priorities, briefs) may reach the site until
   ai.artur.work signs a distribution agreement — raw keyword/SERP records, a raw export and MCP tools that hand
   DataForSEO data to agents need that agreement. Terms §7.1 also forbids using SERP data to compete with the search
   engines.
   **Confirmed in writing by DataForSEO Customer Success (2026-11-10):** (1) the plugin with the site owner's own
   credentials needs no separate agreement; (2) processed analytics sold from prepaid gateway balances are allowed
   under the standard Terms, no cap on end users; (3) record-level data through the gateway's API or MCP needs a
   separate written agreement, priced individually from projected volumes (send endpoints, volumes and billing to get
   a proposal). Caching and reuse are unrestricted for (1) and (2) — the 7 d SERP / 30 d volume TTLs are freshness
   guidance, not licence terms. SEO audits, keyword research, rank tracking and analytics are ordinary uses outside
   §7.1 (no per-product confirmation is issued). No attribution required; white-label plugin and analytics allowed,
   white-label API access only under (3). The affiliate programme fits the plugin (25 % of referred customers'
   payments for 365 days, $1,000 cap per customer): the wizard's "Try DataForSeo for free" link opens
   `api.signup_url` in a new tab, marked "(Ad)" with a commission disclosure under it.
3. **sSeo extension point.** A document-form hook (event or Blade stack) in sSeo would let suggestions sit next to its
   fields instead of in a separate block; its README lists "AI-Powered SEO Recommendations", "SEO Health Check" and
   "Real-time SEO Analytics" as not done — this package can provide them through such a hook.
4. **Verticals.** The lexicon is real estate first; which verticals follow (shop, services, local business)?

## Appendix — measured on 2026-11-09 (bazadomu.com study, `evolution/tmp/bazadomu`)

| Fact | Value |
|---|---|
| SERP `task_post` priority 1 / 2; `live/regular` depth 10; `live` depth 100 | $0.0006 / $0.0012; $0.002; $0.01 |
| Labs live endpoints | $0.012 per request + $0.00012 per item; ×10 for `historical_bulk_traffic_estimation`, `domain_metrics_by_categories`, `domain_whois_overview` |
| Keywords Data Google Ads `search_volume`, `keywords_for_keywords` (live) | $0.09 per task (≤ 1000 keywords / ≤ 20 seeds) |
| OnPage crawl | $0.00015 per page; + resources $0.0003; + JS $0.00135; browser rendering $0.0051 total |
| `appendix/user_data`, `locations_and_languages`, `tasks_ready`, `task_get` | free |
| Whole study: 3 markets, ~3,500 keywords, 283 SERPs, 513 domains, matrix | $1.76 (account balance before/after) |
| Index coverage found | 7 URLs indexed, all `/search~xx?...`; 0 of 65 landing pages |
| Naive vs deduplicated demand | ×7.6 on the English "Prague, rent" page (Ads buckets); ×1.19 over all pages, mostly Labs keywords that are already distinct |
