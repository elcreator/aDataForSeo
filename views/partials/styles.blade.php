{{-- Inlined so the module renders correctly before any publish step. --}}
<style>
    :root {
        --sx-bg: #f6f7f9; --sx-panel: #fff; --sx-control: #fff; --sx-hover: #fafbfc; --sx-chip: #eef0f3;
        --sx-border: #dfe3e8; --sx-text: #23282d; --sx-muted: #6b7280;
        --sx-accent: #2f6fd0; --sx-accent-text: #fff; --sx-accent-soft: #eaf1fb; --sx-warn: #b45309; --sx-warn-soft: #fef6e7;
        --sx-danger: #c0392b; --sx-danger-soft: #fdf3f2; --sx-ok: #1e7d47; --sx-ok-soft: #e8f6ee;
        --sx-code-bg: #1f2937; --sx-code-text: #e5e7eb; --sx-shadow: rgba(15, 23, 42, .45);
    }
    /* Inside evo-ui (Evolution 3.6+): the manager's theme, light or dark, drives every colour. */
    .sx-evo {
        --sx-bg: var(--color-base-200, #f6f7f9); --sx-panel: var(--color-base-100, #fff); --sx-control: var(--color-base-100, #fff);
        --sx-hover: color-mix(in oklab, var(--color-base-content, #23282d) 4%, transparent);
        --sx-chip: color-mix(in oklab, var(--color-base-content, #23282d) 9%, transparent);
        --sx-border: var(--color-base-300, #dfe3e8); --sx-text: var(--color-base-content, #23282d);
        --sx-muted: color-mix(in oklab, var(--color-base-content, #23282d) 62%, transparent);
        --sx-accent: var(--color-primary, #2f6fd0); --sx-accent-text: var(--color-primary-content, #fff);
        --sx-accent-soft: color-mix(in oklab, var(--color-primary, #2f6fd0) 14%, transparent);
        --sx-warn: var(--color-warning, #b45309); --sx-warn-soft: color-mix(in oklab, var(--color-warning, #b45309) 14%, transparent);
        --sx-danger: var(--color-error, #c0392b); --sx-danger-soft: color-mix(in oklab, var(--color-error, #c0392b) 12%, transparent);
        --sx-ok: var(--color-success, #1e7d47); --sx-ok-soft: color-mix(in oklab, var(--color-success, #1e7d47) 14%, transparent);
        color: var(--sx-text); font: 14px/1.55 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; min-height: 100vh;
    }
    .sx-evo ul { list-style: disc; }
    .sx-icon { width: 1.1em; height: 1.1em; vertical-align: -.17em; flex-shrink: 0; stroke-width: 2; }
    * { box-sizing: border-box; }
    [hidden] { display: none !important; }
    /* Always reserve the scrollbar's width: short and long tabs would otherwise shift the page sideways. */
    html { overflow-y: scroll; scrollbar-gutter: stable; }
    body { margin: 0; background: var(--sx-bg); color: var(--sx-text); font: 14px/1.55 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
    .sx-shell { padding: 1.25rem 1.5rem 3rem; max-width: 1400px; margin: 0 auto; }
    .sx-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; flex-wrap: wrap; margin-bottom: 1rem; }
    .sx-head h1 { margin: 0; font-size: 1.35rem; }
    .sx-domain { font-weight: 400; color: var(--sx-muted); font-size: 1rem; margin-left: .35rem; }
    .sx-sub { margin: .15rem 0 0; color: var(--sx-muted); }
    .sx-summary { color: var(--sx-muted); font-size: .9rem; display: flex; gap: .9rem; flex-wrap: wrap; align-items: center; }
    .sx-summary b { color: var(--sx-text); }
    .sx-account { display: inline-flex; align-items: center; gap: .4rem; }
    /* A notification dot on the corner of a button or tab. */
    .sx-open-account, .sx-tab { position: relative; }
    .sx-dot { position: absolute; top: -.25rem; right: -.25rem; width: .6rem; height: .6rem; border-radius: 50%; background: var(--sx-danger); box-shadow: 0 0 0 2px var(--sx-bg); pointer-events: none; }
    .sx-tab .sx-dot { top: .35rem; right: .2rem; }
    .sx-badge-link { font: inherit; font-size: .72rem; border: 0; cursor: pointer; line-height: inherit; }
    .sx-badge-link:hover { text-decoration: underline; }
    .sx-badge-link:focus-visible { outline: 2px solid var(--sx-accent); outline-offset: 1px; }

    .sx-alert { padding: .7rem .9rem; border-radius: 6px; margin-bottom: 1rem; border-left: 3px solid; display: flex; flex-direction: column; gap: .35rem; }
    .sx-alert-warn { background: var(--sx-warn-soft); border-color: var(--sx-warn); }
    .sx-alert-error { background: var(--sx-danger-soft); border-color: var(--sx-danger); color: var(--sx-danger); }
    .sx-alert-info { background: var(--sx-accent-soft); border-color: var(--sx-accent); }
    .sx-alert-ok { background: var(--sx-ok-soft); border-color: var(--sx-ok); }
    code, .sx-code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: .8rem; background: var(--sx-chip); padding: .1rem .4rem; border-radius: 4px; word-break: break-all; }

    /* Wraps instead of scrolling sideways: a hidden horizontal scrollbar hid the last tabs in a narrow manager frame. */
    .sx-tabs { display: flex; flex-wrap: wrap; gap: .25rem; border-bottom: 1px solid var(--sx-border); margin-bottom: 1rem; }
    .sx-tab { font: inherit; background: none; border: 0; border-bottom: 2px solid transparent; padding: .6rem .9rem; cursor: pointer; color: var(--sx-muted); white-space: nowrap; }
    .sx-tab:hover { color: var(--sx-text); }
    .sx-tab[aria-selected="true"] { color: var(--sx-accent); border-bottom-color: var(--sx-accent); font-weight: 600; }
    .sx-count { display: inline-block; min-width: 1.3rem; padding: 0 .35rem; margin-left: .3rem; border-radius: 999px; background: var(--sx-accent); color: var(--sx-accent-text); font-size: .7rem; text-align: center; }

    .sx-panel { background: var(--sx-panel); border: 1px solid var(--sx-border); border-radius: 8px; padding: 1rem; margin-bottom: 1rem; }
    .sx-panel h2 { margin: 0 0 .6rem; font-size: 1rem; }
    .sx-panel h3 { margin: .8rem 0 .4rem; font-size: .9rem; }
    .sx-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: .6rem; margin-bottom: 1rem; }
    .sx-grow { flex: 1; }
    .sx-muted { color: var(--sx-muted); }
    .sx-small { font-size: .85rem; }

    .sx-btn { font: inherit; display: inline-flex; align-items: center; gap: .35rem; border: 1px solid var(--sx-border); background: var(--sx-control); color: var(--sx-text); border-radius: 6px; padding: .4rem .75rem; cursor: pointer; text-decoration: none; }
    .sx-btn:hover { border-color: var(--sx-accent); }
    .sx-btn[disabled] { opacity: .55; cursor: not-allowed; }
    .sx-btn-primary { background: var(--sx-accent); border-color: var(--sx-accent); color: var(--sx-accent-text); }
    .sx-btn-primary:hover { filter: brightness(1.07); }
    .sx-btn-danger { color: var(--sx-danger); }
    .sx-btn-small { padding: .2rem .5rem; font-size: .8rem; }
    .sx-btn-try { background: var(--sx-ok); border-color: var(--sx-ok); color: var(--sx-panel); font-weight: 600; }
    .sx-try { display: inline-flex; flex-direction: column; gap: .2rem; vertical-align: top; }
    .sx-ad { font-weight: 400; font-size: .75rem; opacity: .85; }
    .sx-try-row { display: inline-flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
    .sx-try-lead { color: var(--sx-text); }
    .sx-try .sx-help { color: var(--sx-muted); font-size: .75rem; }

    .sx-badge { display: inline-block; padding: .05rem .45rem; border-radius: 999px; font-size: .72rem; background: var(--sx-chip); color: var(--sx-muted); white-space: nowrap; }
    .sx-badge.is-critical, .sx-badge.is-failed { background: var(--sx-danger); color: var(--sx-panel); }
    .sx-badge.is-high, .sx-badge.is-hard { background: var(--sx-danger-soft); color: var(--sx-danger); }
    .sx-badge.is-medium, .sx-badge.is-awaiting_approval, .sx-badge.is-waiting { background: var(--sx-warn-soft); color: var(--sx-warn); }
    .sx-badge.is-low, .sx-badge.is-easy, .sx-badge.is-done, .sx-badge.is-exists { background: var(--sx-ok-soft); color: var(--sx-ok); }
    .sx-badge.is-P1 { background: var(--sx-accent); color: var(--sx-accent-text); }
    .sx-badge.is-P2 { background: var(--sx-accent-soft); color: var(--sx-accent); }
    .sx-badge.is-running, .sx-badge.is-queued { background: var(--sx-accent-soft); color: var(--sx-accent); }

    .sx-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(420px, 1fr)); gap: 1rem; }
    .sx-card { background: var(--sx-panel); border: 1px solid var(--sx-border); border-radius: 8px; padding: 1rem; display: flex; flex-direction: column; gap: .55rem; }
    .sx-card.is-critical { border-left: 4px solid var(--sx-danger); }
    .sx-card.is-high { border-left: 4px solid color-mix(in oklab, var(--sx-danger) 55%, var(--sx-warn)); }
    .sx-card.is-medium { border-left: 4px solid var(--sx-warn); }
    .sx-card h3 { margin: 0; font-size: 1rem; }
    .sx-card .sx-why { color: var(--sx-muted); margin: 0; }
    .sx-card ul { margin: 0; padding-left: 1.1rem; font-size: .85rem; }
    .sx-card li { word-break: break-all; }
    .sx-card-actions { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: auto; }

    .sx-runbar { display: flex; gap: .5rem; flex-wrap: wrap; }

    table.sx-table { width: 100%; border-collapse: collapse; font-size: .85rem; }
    .sx-table th, .sx-table td { text-align: left; padding: .4rem .5rem; border-bottom: 1px solid var(--sx-border); vertical-align: top; }
    .sx-table th { color: var(--sx-muted); font-weight: 600; white-space: nowrap; }
    .sx-table td.num, .sx-table th.num { text-align: right; font-variant-numeric: tabular-nums; }
    .sx-table tr:hover td { background: var(--sx-hover); }
    .sx-scroll { overflow-x: auto; }
    .sx-up { color: var(--sx-ok); }
    .sx-down { color: var(--sx-danger); }

    .sx-progress { height: 6px; background: var(--sx-chip); border-radius: 999px; overflow: hidden; min-width: 80px; }
    .sx-progress > div { height: 100%; background: var(--sx-accent); }

    .sx-form { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: .8rem 1rem; }
    .sx-field { display: flex; flex-direction: column; gap: .25rem; }
    .sx-field label { font-weight: 600; font-size: .85rem; }
    .sx-field .sx-help { color: var(--sx-muted); font-size: .78rem; }
    .sx-field .sx-err { color: var(--sx-danger); font-size: .78rem; }
    .sx-fieldset { border: 0; margin: 0; padding: 0; min-width: 0; }
    .sx-fieldset legend { font-weight: 600; font-size: .85rem; padding: 0; margin-bottom: .25rem; }
    .sx-radios { display: flex; flex-direction: column; gap: .35rem; }
    .sx-radio { display: flex; align-items: flex-start; gap: .5rem; padding: .45rem .6rem; border: 1px solid var(--sx-border); border-radius: 6px; background: var(--sx-control); cursor: pointer; }
    .sx-radio:has(input:checked) { border-color: var(--sx-accent); background: var(--sx-accent-soft); }
    .sx-radio input { margin: .2rem 0 0; accent-color: var(--sx-accent); }
    .sx-radio b { font-weight: 600; display: block; }
    .sx-radio small { color: var(--sx-muted); display: block; }
    .sx-radio:focus-within { outline: 2px solid var(--sx-accent); outline-offset: 1px; }
    input[type=text], input[type=password], input[type=number], select, textarea { font: inherit; padding: .4rem .55rem; border: 1px solid var(--sx-border); border-radius: 6px; background: var(--sx-control); color: var(--sx-text); width: 100%; }
    textarea { min-height: 7rem; font-family: ui-monospace, Menlo, Consolas, monospace; font-size: .8rem; }
    pre.sx-json { background: var(--sx-code-bg); color: var(--sx-code-text); padding: .8rem; border-radius: 6px; overflow: auto; max-height: 30rem; font-size: .75rem; }

    .sx-wizard { max-width: 760px; }
    .sx-steps { display: flex; gap: .5rem; margin-bottom: 1rem; }
    .sx-steps span { flex: 1; text-align: center; padding: .3rem; border-bottom: 3px solid var(--sx-border); color: var(--sx-muted); font-size: .85rem; }
    .sx-steps span.is-on { border-color: var(--sx-accent); color: var(--sx-accent); font-weight: 600; }
    .sx-choice { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: .8rem; margin-bottom: 1rem; }
    .sx-choice button { text-align: left; padding: .9rem; border: 1px solid var(--sx-border); border-radius: 8px; background: var(--sx-control); color: var(--sx-text); cursor: pointer; font: inherit; }
    .sx-choice button.is-on { border-color: var(--sx-accent); background: var(--sx-accent-soft); }
    .sx-choice b { display: block; margin-bottom: .2rem; }

    .sx-modal-back { position: fixed; inset: 0; background: var(--sx-shadow); display: flex; align-items: flex-start; justify-content: center; padding: 4vh 1rem; z-index: 50; overflow-y: auto; }
    .sx-modal { background: var(--sx-panel); color: var(--sx-text); border-radius: 10px; width: 100%; max-width: 720px; box-shadow: 0 20px 50px rgba(0,0,0,.25); }
    .sx-modal header { padding: .9rem 1rem; border-bottom: 1px solid var(--sx-border); display: flex; justify-content: space-between; align-items: center; }
    .sx-modal header h2 { margin: 0; font-size: 1.05rem; }
    .sx-modal .sx-body { padding: 1rem; display: flex; flex-direction: column; gap: .8rem; }
    .sx-modal footer { padding: .8rem 1rem; border-top: 1px solid var(--sx-border); display: flex; justify-content: flex-end; gap: .5rem; }
    .sx-diff { display: grid; grid-template-columns: 9rem 1fr; gap: .25rem .75rem; font-size: .85rem; }
    .sx-diff .sx-before { color: var(--sx-danger); text-decoration: line-through; word-break: break-word; }
    .sx-diff .sx-after { color: var(--sx-ok); word-break: break-word; }
    .sx-empty { text-align: center; padding: 2.5rem 1rem; color: var(--sx-muted); }
    .sx-empty i { font-size: 2rem; display: block; margin-bottom: .5rem; }
    .sx-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
    .sx-money { font-variant-numeric: tabular-nums; }
    @media (max-width: 700px) { .sx-cards { grid-template-columns: 1fr; } }
</style>
