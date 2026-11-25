---
name: adataforseo
description: Use aDataForSeo eMCP tools to check Evolution CMS index coverage, audit findings, page demand, SERPs and rankings, or to estimate checks, apply fixes, create drafts and undo changes.
license: MIT
compatibility: Evolution CMS 3.5.2+ with evolution-cms/emcp and elcreator/adataforseo installed; an MCP client connected to the site's eMCP server
metadata:
  version: "1.1.0"
  author: Artur Kyryliuk <mail@artur.work>
  tags: evolution-cms, mcp, seo, dataforseo
  package: elcreator/adataforseo
---

# aDataForSeo via eMCP

## Purpose

Read what aDataForSeo found about the site's search visibility (index coverage,
technical audit, keyword demand, SERP difficulty, rankings), start checks,
create missing-page drafts, apply suggested fixes and undo recorded changes.
Every call acts as the manager who owns the eMCP token: same permissions and
the same results as on **Modules → Search**.

## When to Use

Use when the user asks why pages are not in Google, what to fix first for SEO,
which pages are missing for their markets, how rankings changed, what a check
would cost, to start a check, create a missing-page draft, apply a suggested
title, description or redirect, or undo an applied change.

Do NOT use for sites without aDataForSeo, for general SEO advice without site
data, or for document edits unrelated to a finding (use the `evolution-cms-mcp`
skill).

## Prerequisites

- The site is connected as described in the `evolution-cms-mcp` skill and
  `tools/list` contains `adataforseo.*` tools; otherwise install
  `php artisan package:installrequire elcreator/adataforseo "*"`, run
  `php artisan migrate`, and grant the role the `adataforseo` permission.
- Listing tools requires `mcp:read` and calling tools requires `mcp:call`.
  `adataforseo.apply_fix`, `.create_draft`, `.start_job` and `.undo_change`
  also need write tools enabled in
  **Tools → MCP settings** plus `mcp:write`.
- Undo can delete a draft, so `.undo_change` also needs destructive tools
  enabled and `mcp:destructive` (or the site's configured destructive scope).
- Fixes that write titles, descriptions or redirects need sSeo (`seiger/sseo`)
  and the CMS permissions for that change: `save_document` and access to the
  document for sSeo fields; `exec_module` (configurable) for redirects.
- Drafts need `new_document`, `save_document` and access to the chosen parent;
  undoing a draft also needs `delete_document` and access to the document.
- Starting jobs needs `adataforseo_spend`, the queue worker and the
  `schedule:run` cron. Approvals and schedules stay on the module page.

## Instructions

1. **Prioritise fixes with `adataforseo.audit`.** Summarise the highest-effect
   cards. The `count` is the total affected count. Issue cards sample up to
   eight URLs in `items`. Use their issue IDs to preview fixes. For sitemap and
   index counts or the list of unindexed pages, use `adataforseo.coverage`.
2. **Choose the report that answers the question.**
   - Use `adataforseo.matrix` to compare page demand and priority or find gaps.
      Filters combine with AND. With no filters, it shows up to 1,000 targets
      for the project in priority order. Totals describe those returned rows.
   - Use `adataforseo.serp` with a matrix target ID to explain the competition for
      one page. Check `serp.checked_at` and `serp.data_fetched_at` when available.
      Gateway snapshots contain aggregates, direct snapshots can list
      competitors, and `serp: null` means unchecked.
   - Use `adataforseo.rankings` to find position gains or losses for the site.
     It returns up to 12 tracking dates. A positive delta is an improvement
     across the two newest dates. Null means the comparison is unavailable.
3. **Preview every fix.** Call `adataforseo.apply_fix` with `preview: true`
   and show the `changes` (field, before, after). If `available` is false,
   report the manual instructions. Include any `fields` or
   `new_url` overrides in both preview and apply. Apply the same `issue`
   after the user authorizes the change.
4. **Know the fix kinds.** `seo_fields` writes sSeo fields of the document;
   `redirect` adds a 301 (pass `new_url` when no target is suggested);
   `manual` changes nothing: applying it only marks the finding resolved, so
   do it only after the user has done the work from `instructions`.
5. **Create missing-page drafts.** Use `adataforseo.create_draft` with a
   `target` from `matrix`, the chosen `parent` (0 for root), optional
   `pagetitle` and `preview: true`. Show the preview, then apply the same
   inputs when authorized. The document remains unpublished.
6. **Price before starting work.** Call `adataforseo.estimate` with `kind`
   (`coverage`, `audit`, `research`, `difficulty`, `track`, `competitors`, or
   `custom`) and any `options`, such as `{"crawl_pages": 100}` for an audit.
   Direct DataForSEO estimates use USD and gateway estimates use EUR.
   The estimate tool omits currency, so check the project's billing mode in
   the module before quoting it. A null amount means the cost is unknown.
   Gateway billing supports `coverage`, `audit` and `difficulty`. Show the estimate,
   then call `adataforseo.start_job` with the same kind and options when authorized.
   Report its UUID, status, estimate, spent amount and currency. Jobs in
   `awaiting_approval` need approval on the module page. Check progress there.
   The site's budget limits apply to every job.
7. **Report applied changes** with their ids. Use `adataforseo.undo_change`
   with a `change` id when the user requests undo. It refuses values edited
   since applying and drafts that were edited or published.

## Tools

| Tool | Kind | Key arguments |
|---|---|---|
| `adataforseo.audit` | read | none: cards with issue ids |
| `adataforseo.coverage` | read | none: latest coverage result, not-indexed pages |
| `adataforseo.matrix` | read | `country` (e.g. `CZ`), `lang`, `tier` (`P1`-`P3`), `status` (`exists`, `not_indexed`, `missing`, `needs_locale`) |
| `adataforseo.serp` | read | `target`* (id from `matrix`) |
| `adataforseo.rankings` | read | none: positions per keyword and their change |
| `adataforseo.estimate` | read | `kind`*, `options` (object) |
| `adataforseo.apply_fix` | write | `issue`* (id from `audit`), `fields` (object), `new_url`, `preview` |
| `adataforseo.create_draft` | write | `target`* (id from `matrix`), `parent`, `pagetitle`, `preview` |
| `adataforseo.start_job` | write | `kind`*, `options` (same as estimate) |
| `adataforseo.undo_change` | destructive write | `change`* (id returned by apply or draft) |

`*` required.

`fields` can override `meta_title`, `meta_description`, `meta_keywords`,
`robots`, `canonical_url` and `exclude_from_sitemap`. Job `options` accept
`crawl_pages`, `javascript`, `priority`, `depth`, `recheck` and `keywords`;
`custom` jobs also accept `endpoint`, `tasks` and `variant`.

## Examples

### What to fix first

```json
{"name": "adataforseo.audit", "arguments": {}}
{"name": "adataforseo.apply_fix", "arguments": {"issue": 412, "preview": true}}
```

After the user authorizes the previewed fix:

```json
{"name": "adataforseo.apply_fix", "arguments": {"issue": 412}}
```

### Fix a broken page with a redirect

```json
{"name": "adataforseo.apply_fix", "arguments": {"issue": 97, "new_url": "/catalog/", "preview": true}}
```

### Missing pages for a market

`adataforseo.matrix` `{"country": "CZ", "status": "missing", "tier": "P1"}`, then
`adataforseo.serp` for a target to judge difficulty. Preview a draft with
`adataforseo.create_draft` `{"target": 23, "parent": 7, "preview": true}`,
then repeat without `preview` when authorized. Save the returned change id
for undo.

```json
{"name": "adataforseo.create_draft", "arguments": {"target": 23, "parent": 7, "preview": true}}
```

After the user authorizes the previewed draft:

```json
{"name": "adataforseo.create_draft", "arguments": {"target": 23, "parent": 7}}
```

### Undo a recorded change

Use an id from the returned `changes` array, rather than a target or document
id. For example, if the change id is 88 and the user requests undo:

```json
{"name": "adataforseo.undo_change", "arguments": {"change": 88}}
```

Report `change.undone_at`; a refusal means the change was not undone.

### Estimate and start an audit

```json
{"name": "adataforseo.estimate", "arguments": {"kind": "audit", "options": {"crawl_pages": 100}}}
```

After showing the estimate and obtaining authorization to spend:

```json
{"name": "adataforseo.start_job", "arguments": {"kind": "audit", "options": {"crawl_pages": 100}}}
```

## Limitations

- Job approval, cancellation, progress polling, scheduling and ignoring
  findings stay on the module page.
- `apply_fix` takes finding ids; missing-page drafts use `create_draft` and
  target ids. Undo takes one change id at a time.
- Results cover the site's default project (the `site_url` domain).
- `coverage` is `null` until a coverage check has finished.
- Without sSeo, `seo_fields` and `redirect` fixes are refused; preview still works.

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| No `adataforseo.*` tools | Package missing, `adataforseo` permission missing, or `mcp.enabled` false | Install, migrate, grant the permission |
| HTTP `403` `tool_denied` / `scope_denied` on a write tool | Write tools disabled or token without `mcp:write` | Enable write tools; recreate the token |
| Empty `cards` | There are no saved open findings or high-priority page gaps to show | Check the latest run in the module before concluding the site has no issues |
| `coverage: null` | There is no completed coverage report yet | Check the current job in the module, or estimate a new coverage check if needed |
| JSON-RPC `-32602` `forbidden` | Manager lacks the module, document or redirect permission | Grant it, or let a permitted manager apply |
| `-32602` `not_found` | Issue or target id from another run | Re-read `audit` / `matrix` |
| `-32602` `error_sseo_missing` | sSeo not installed | Install `seiger/sseo` or apply the change by hand |
| `-32602` `error_redirect_exists` | A redirect for that URL already exists | Check sSeo redirects |
| `-32602` `new_url` | Missing, invalid or looping redirect target | Pass an absolute URL or a path starting with `/` |
| `-32602` `error_fix_nothing` / `error_fix_no_document` | Nothing to write, or the finding has no document | Read `instructions` and fix by hand |
| `-32602` `error_kind` | Unknown job kind | Use a kind listed above |
| Job status `awaiting_approval` | Unknown estimate or above the approval threshold | Review and approve on the module page |
| `-32602` `error_undo_changed` / `error_undo_draft_edited` | The value or draft changed after applying | Review it in the module; do not overwrite later edits |
