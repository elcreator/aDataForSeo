# What is not done yet (start here)

State on 2026-11-27. The plugin (`elcreator/adataforseo`, this repo) and the ai.artur.work gateway
(`C:\projects\ai-artur-work`, feature docs `docs/features/30-seo-data.md` and `31-seo-writing.md`) are both on `main`
and deployed: the plugin is pushed to GitHub, the gateway is copied to prod with `tmp/prod-deploy.sh` (no auto-deploy,
no reviewers, no customers yet). `AGENTS.md` and `docs/DESIGN.md` are the contracts; read them first.

Done on 2026-11-26/27, for context: the `gateway` Writer for titles and descriptions; DataForSEO refunds are counted (SERP shorter than its depth, OnPage crawl below
`max_crawl_pages`), search operators cost ×5, one-click fixes for short titles and missing canonicals, and the
gateway's daily billing reconciliation (`SeoData\Reconciliation`, admin panel + mail to `admin_email`).

## Plugin features (DESIGN.md "Wave 5")

1. **`gateway` Writer, the rest of feature 31.** Titles and descriptions are built (2026-11-27,
   `Services\GatewayWriter`, "Suggest with AI" in the fix dialogs, setting `writer` on the Settings tab; verified
   against the real gateway: EUR 0.0003 per call). Not built: `/seo/schema` (JSON-LD from facts, free: a natural next
   step through sSeo's `structured_data`), `/seo/check` (scaled-content risk before publishing a draft),
   `/seo/translate` (a draft in a missing locale, `needs_locale` targets) and `/seo/media`. The DocumentPanel
   (`OnDocFormRender`) has no AI button yet.
2. **AI visibility.** The gateway has `seo.ai_visibility` (SERP `ai_overview` + LLM Scraper, priced per query and
   platform). The plugin has no plan, analyser or card for it, in either billing mode. Own key: `ai_overview` is
   already in fetched SERPs (free); LLM Mentions only when `llm_mentions_subscription_expiry_date` is in the future.
3. **Local businesses.** Wizard question "do customers visit you?" → SERP maps / local pack tracking and Business
   Data checks. Nothing built on either side.
4. **Backlinks.** Only when `backlinks_subscription_expiry_date` is in the future. Nothing built.

## Smaller fixes

5. **Audit estimate in own-key mode is the full cap.** `Api\Estimator` prices `on_page/task_post` as
   `max_crawl_pages` × price, but DataForSEO refunds the pages not crawled. Show a range (1 page … cap) like the
   gateway's quote; budgets keep using the ceiling.
6. **Gateway sitemap fetch gets 403 on some sites** (myfunkids.com). `SafeFetch` sends `User-Agent: ai.artur.work
   fetcher`; bot protection likely blocks it. Try a browser-like or documented crawler UA, and tell the plugin user
   to allow it; meanwhile coverage compares with the plugin's own document list.
7. **Own-key reconciliation per task.** The plugin's `adfs:tick` compares the account balance with the ledger
   (`Jobs`, `budget.drift_alert`), which payments blur. It could use `serp/id_list` and `on_page/id_list` (free,
   final cost per task, tag `<job uuid>:<step id>`) the way the gateway's `SeoData\Reconciliation` does.
8. **Gateway reconciliation does not cover live calls without `id_list`** (Labs `bulk_traffic_estimation`, Google
   Ads). Their response `cost` is final, so the risk is low; if a provider rule changes, compare the day total in
   `user_data` → `money.statistics.day.total` with the gateway's sum.
9. The first gateway reconciliation (after 2026-11-27) will list the manual probe crawl `11262120-…` (tag
   `adfs-onpage-refund-probe`, $0.00015) as "not from the gateway". That is expected; it stays known for 14 days.

## Open questions (DESIGN.md "Open questions")

10. Shared queue package for aMount / aIMage / this plugin, or keep copies.
11. **sSeo extension point: proposed upstream** (2026-11-27, branch `feat/seo-fields-extension-event` of
    `elcreator/sSeo`, PR to `Seiger/sSeo`). The event `sSeoFieldsRender` gets `resource_id`, `resource_type`, `lang`,
    `domain_key` and the current `fields`; the HTML that listeners return is shown under the meta description in every
    language tab. It was checked in `adfs-serve`, which now has sSeo installed from that branch
    (`core/custom/packages/sseo`; snapshot taken before: image `adataforseo-serve:before-sseo-20261127`). Still to
    build in this plugin: a listener that shows the target query, demand, position and template suggestions there, with
    "Use this" filling `sseo[<lang>][meta_title]` / `[meta_description]` and setting `documentDirty`. Also "Suggest with
    AI" (needs the module's base URL and a CSRF token on the document form). When the event fires, the separate
    `DocumentPanel` block (`OnDocFormRender`) should step aside. Until the PR is merged and released, keep
    `DocumentPanel` as it is.
12. Which verticals follow real estate in the lexicon (shop, services, local business).
13. Record-level data (raw keywords/SERPs, research, tracking, competitors) through the gateway needs a separate
    written agreement with DataForSEO; until then those stay own-key only.
