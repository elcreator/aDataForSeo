# aDataForSeo

Gets the Evolution CMS site it is installed on found in search. It reads
[DataForSEO](https://dataforseo.com/) — index coverage, a technical audit,
keyword demand, SERPs, rankings — and turns it into a short list of concrete
changes to **this** site, ranked by expected effect, each applied with one
click: sSeo titles and descriptions, redirects, draft pages. Work runs in the
background on the CMS task queue (or sTask), always within a budget.

- **Beginners** get a wizard (key or free demo data → where are your customers →
  budget) and plain-language "do this now" cards.
- **Professionals** get the page matrix with real (deduplicated) demand,
  difficulty measured on the actual top 10, keywords, rankings, the ledger, an API
  explorer with a price preview, CSV/XLSX exports and MCP tools for agents.

Package `elcreator/adataforseo`, Evolution CMS 3.5+, PHP 8.3+, GPL-3.0-or-later. On 3.6+ with
[evo-ui](https://github.com/evolution-cms/evo-ui) the module page lives in evo-ui's shell and follows the
manager's light/dark theme, with Tabler icons; on 3.5 it is a standalone page with the manager's Font Awesome.

## Install

From the manager (Extras) or the command line of the site:

```sh
php core/artisan package:installrequire elcreator/adataforseo "*"
php core/artisan migrate
```

Then open **Modules → Search**. The migration adds three permissions (role 1
holds all of them):

| Permission | Allows |
|---|---|
| `adataforseo` | open the module, read reports and cached data |
| `adataforseo_spend` | start or approve work that costs money, within the budgets |
| `adataforseo_manage` | keys, budgets, schedules, project settings |

Applying a fix additionally needs what the CMS needs for that change, checked at
the moment of the click: `save_document` and access to the document for sSeo
fields, `new_document` for drafts, the sSeo module permission for redirects.

### Background work

Jobs run in slices on the CMS task queue. A server needs what any Evolution
CMS 3.5 site with background tasks needs:

```sh
php core/artisan system:task-worker          # a long-running worker (supervisor/systemd)
* * * * * cd /path/to/core && php artisan schedule:run >> /dev/null 2>&1
```

The scheduler runs `adfs:tick` every minute: it wakes jobs whose DataForSEO tasks
should be ready (a slice never sleeps), re-queues jobs whose worker died, starts
scheduled checks, purges old data and reconciles the ledger with the account
balance once a day. With [sTask](https://github.com/Seiger/sTask) installed, jobs
run as an sTask worker instead (`queue.driver`: `auto` / `system` / `stask`).

## Your DataForSEO account

With your own DataForSEO account the data is yours: shown, stored, exported and
cached as you like (confirmed by DataForSEO's customer success team, November
2026). No account yet? The wizard has a **Try DataForSeo for free** link (an
affiliate link, marked as an ad and disclosed; the plugin's author may earn a
commission; change it with `api.signup_url`), and
**demo data** works without any account: invented but realistic answers built
around your own pages, nothing leaves the server. DataForSEO's free **sandbox**
is the third option.

The login is stored encrypted (AES-256-GCM; key from `ADATAFORSEO_SECRET` or a
generated `core/custom/adataforseo.secret`), masked in every response, never
logged and never put in queue payloads. `DATAFORSEO_LOGIN` / `DATAFORSEO_PASSWORD`
in the environment also work.

## Money rules

- **No call without a price.** Prices come from DataForSEO's own price table
  (`appendix/user_data`, free), never hardcoded. Every paid call is estimated
  first and refused when it would break the job cap, the monthly cap or the
  balance.
- **Unknown is never zero.** When the price cannot be known in advance (a deep
  SERP, a result count only the answer will tell) the job waits for a manager's
  approval; after the first run the estimate is calibrated from the ledger.
- **The response's `cost` is the truth** and is booked before the answer is used.
  `task_get` answers repeat the price of the task already charged and are booked
  at 0.
- Above `approve_above` a job waits in "waiting for approval". Every refusal is
  written to the event log.
- Repeated questions are answered from the cache (SERP 7 days, keyword data 30
  days), free.

## What it checks

| Check | What you get |
|---|---|
| Index coverage | your sitemap vs Google's `site:` results: pages Google does not show, search pages it shows instead |
| Technical audit | OnPage crawl: missing/duplicate titles and descriptions, 404s (with a redirect fix), redirect chains, canonicals, closed pages, broken links |
| Keyword demand | what people search for in your markets and languages, close variants counted once, mapped to existing pages or to pages worth creating, with a priority |
| Difficulty | the current top 10 of each important page, scored by the real strength of the sites there |
| Rank tracking | positions over time, a dashboard widget |
| Competitors | who wins your searches and which of their pages you lack |

Labs covers ~90 countries and only their local language; other languages
automatically go through Google Ads data. Places are matched in every spelling
you give (`Praha: en=Prague, uk=Прага`); the vocabulary of the vertical lives in
`config/lexicon/real_estate.php`.

## sSeo, sLang, sMultisite

With [sSeo](https://github.com/Seiger/sSeo) installed, fixes write through
`sSeo::updateSeoFields()` (changed fields only) and its redirect table; every
change keeps the previous value and can be undone while the field still holds
what was written. Without sSeo the same findings are shown as instructions.
sLang languages and sMultisite domains are respected.

## Configuration

`config/aDataForSeo.php`, merged under `cms.settings.aDataForSeo`; override in
`core/custom/config/cms/settings/aDataForSeo.php`. Highlights: `api.env`,
`api.cache_ttl`, `queue.driver`, `queue.pingback` (DataForSEO wakes waiting jobs
via a signed public URL), `limits.*`, `budget.monthly_cap` (5.00),
`budget.job_cap` (2.00), `budget.approve_above` (0.50), `research.weights`.

## Commands

```sh
php core/artisan adfs:tick                      # what the scheduler runs every minute
php core/artisan adfs:demo --documents --run    # seed a demo project and run every check on demo data
php core/artisan adfs:demo --sandbox --run      # the same against DataForSEO's free sandbox
```

## MCP

With [eMCP](https://github.com/evolution-cms/emcp) installed, agents get
`adataforseo.coverage`, `.audit`, `.matrix`, `.serp`, `.rankings` and `.estimate`,
plus `.apply_fix`, `.create_draft`, `.start_job` and `.undo_change` behind eMCP's
write gate, with exactly the permissions of the token's manager. Fix previews
accept the same `fields` and `new_url` overrides as applying; estimates and job
starts accept the same `options`. Drafts remain unpublished, undo refuses later
edits, and jobs still obey budgets and the module's approval gate. Undo can delete
drafts, so it also requires eMCP's destructive tools setting and scope.

The [`adataforseo` skill](skills/adataforseo/SKILL.md) guides agents through
findings, fix previews, draft pages, job estimates and undo. Install it as a
Claude Code plugin from the eMCP marketplace:

```bash
claude plugin marketplace add evolution-cms/evo-mcp
claude plugin install adataforseo@evolution-cms
```

## Development

```sh
EVO_CORE_PATH_TEST=<core with dev deps> php $EVO_CORE_PATH_TEST/vendor/pestphp/pest/bin/pest --no-coverage
ci/demo.sh                                       # CMS + plugin + demo data in Docker, http://localhost:8080/manager/
```

See `docs/DESIGN.md` for the contracts and `ci/README.md` for the pipeline.

## License

GPL-3.0-or-later.
