@verbatim
    // ------------------------------------------------------------------
    // Core: strings, DOM, transport, dialogs, tabs, polling
    // ------------------------------------------------------------------

    var CFG = window.ADATAFORSEO || {};
    var L = CFG.lang || {};
    var BASE = String(CFG.baseUrl || '').replace(/\/$/, '');
    var LANG = document.documentElement.getAttribute('lang') || 'en';
    var POLL_MS = 3000;

    var S = { boot: null, tab: 'home', jobs: [], cards: [], timer: null, wizard: null, data: {} };
    var R = {}; // tab renderers

    function t(key, rep) {
        var s = Object.prototype.hasOwnProperty.call(L, key) ? String(L[key]) : String(key);
        if (rep) { s = s.replace(/:([a-z_]+)/g, function (m, k) { return Object.prototype.hasOwnProperty.call(rep, k) ? String(rep[k]) : m; }); }
        return s;
    }
    function tOr(key, fallback, rep) { return Object.prototype.hasOwnProperty.call(L, key) ? t(key, rep) : fallback; }
    function $(id) { return document.getElementById(id); }

    function h(tag, attrs, kids) {
        var el = document.createElement(tag);
        var a = attrs || {};
        Object.keys(a).forEach(function (k) {
            var v = a[k];
            if (v === false || v === null || v === undefined) { return; }
            if (k === 'class') { el.className = v; }
            else if (k === 'text') { el.textContent = v; }
            else if (k.indexOf('on') === 0 && typeof v === 'function') { el.addEventListener(k.slice(2), v); }
            else if (v === true) { el.setAttribute(k, ''); }
            else { el.setAttribute(k, String(v)); }
        });
        add(el, kids);
        return el;
    }
    function add(el, kids) {
        if (kids === null || kids === undefined || kids === false) { return el; }
        if (Array.isArray(kids)) { kids.forEach(function (k) { add(el, k); }); return el; }
        el.appendChild(typeof kids === 'object' && kids.nodeType ? kids : document.createTextNode(String(kids)));
        return el;
    }
    function clear(el) { while (el.firstChild) { el.removeChild(el.firstChild); } return el; }
    var ICONS = CFG.icons || {};
    var SVG_CACHE = {};
    /** A Tabler SVG from the server (3.6+), parsed as XML rather than HTML; else a Font Awesome class (3.5). */
    function icon(name) {
        if (Object.prototype.hasOwnProperty.call(ICONS, name)) {
            if (SVG_CACHE[name] === undefined) {
                var parser = new DOMParser();
                var doc = parser.parseFromString(String(ICONS[name]), 'image/svg+xml');
                var root = doc.documentElement;
                // Strict XML refuses sloppy markup (repeated attributes); an inert HTML document forgives it.
                if (!root || root.nodeName.toLowerCase() !== 'svg' || doc.getElementsByTagName('parsererror').length) {
                    root = parser.parseFromString(String(ICONS[name]), 'text/html').querySelector('svg');
                }
                SVG_CACHE[name] = root || null;
            }
            if (SVG_CACHE[name]) {
                var svg = document.importNode(SVG_CACHE[name], true);
                svg.setAttribute('class', 'sx-icon');
                svg.setAttribute('aria-hidden', 'true');
                svg.setAttribute('focusable', 'false');
                return svg;
            }
        }
        return h('i', { 'class': 'fa fa-' + name, 'aria-hidden': 'true' });
    }
    function btn(label, o) {
        o = o || {};
        var b = h('button', {
            type: 'button', title: o.title, disabled: !!o.disabled,
            'class': 'sx-btn' + (o.primary ? ' sx-btn-primary' : '') + (o.danger ? ' sx-btn-danger' : '') + (o.small ? ' sx-btn-small' : '') + (o.cls ? ' ' + o.cls : '')
        }, [o.icon ? icon(o.icon) : null, label ? h('span', { text: label }) : null]);
        if (o.onclick) { b.addEventListener('click', function () { o.onclick(b); }); }
        return b;
    }
    function badge(text, kind) { return h('span', { 'class': 'sx-badge' + (kind ? ' is-' + kind : ''), text: text }); }
    function num(n) { if (n === null || n === undefined) { return '—'; } try { return new Intl.NumberFormat(LANG).format(Number(n)); } catch (e) { return String(n); } }
    /** US dollars with a DataForSEO account, euros through ai.artur.work: a figure is shown in the currency it was booked in. */
    function currencySymbol(currency) { return currency === 'EUR' ? '€' : '$'; }
    function money(n, unknownText, currency) {
        if (n === null || n === undefined) { return unknownText || t('ui_price_unknown'); }
        n = Number(n);
        return currencySymbol(currency || (S.boot && S.boot.currency) || 'USD') + n.toFixed(n > 0 && n < 0.1 ? 4 : 2);
    }
    function estimateText(e, currency) {
        if (!e || e.amount === null || e.unknown) { return t('ui_price_unknown'); }
        if (e.high !== null && e.high !== undefined && Number(e.high) > Number(e.amount) + 1e-9) { return money(e.low, null, currency) + ' – ' + money(e.high, null, currency); }
        return (e.exact ? '' : '≈ ') + money(e.amount, null, currency);
    }
    function isGateway() { return !!(S.boot && S.boot.billing === 'gateway'); }
    /** A link from the gateway is followed only when it is a web address; a relative one is the gateway's own page. */
    function gatewayLink(url, base) {
        url = String(url || '');
        if (/^https?:\/\//i.test(url)) { return url; }
        return url.charAt(0) === '/' && /^https?:\/\//i.test(String(base || '')) ? String(base).replace(/\/$/, '') + url : '';
    }
    function when(iso) { if (!iso) { return ''; } var d = new Date(iso); return isNaN(d.getTime()) ? '' : d.toLocaleString(LANG); }
    function live(msg) { var el = $('sx-live'); if (el) { el.textContent = msg; } }

    function api(method, path, body) {
        var opts = { method: method, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };
        if (method !== 'GET') {
            opts.headers['Content-Type'] = 'application/json';
            opts.headers['X-CSRF-TOKEN'] = CFG.csrf || '';
            opts.body = JSON.stringify(body || {});
        }
        return fetch(BASE + path, opts).then(function (r) {
            return r.json().catch(function () { return { ok: false, error: 'http', message: t('error_http_page', { status: r.status }) }; });
        }).catch(function () { return { ok: false, error: 'network', message: t('error_network') }; });
    }
    function errMsg(r) {
        if (!r) { return t('error_network'); }
        var m = r.message || t('error_unknown');
        if (r.errors) { m += ' ' + Object.keys(r.errors).map(function (k) { return r.errors[k]; }).join(' '); }
        return m;
    }
    function notice(target, kind, text) { clear(target); if (text) { add(target, h('div', { 'class': 'sx-alert sx-alert-' + kind, role: kind === 'error' ? 'alert' : 'status', text: text })); } }

    function modal(title, body, actions) {
        var root = $('sx-modal-root');
        var close = function () { clear(root); document.removeEventListener('keydown', esc); };
        var esc = function (e) { if (e.key === 'Escape') { close(); } };
        var footer = h('footer');
        (actions || []).forEach(function (a) { add(footer, a); });
        add(footer, btn(t('ui_close'), { onclick: close }));
        var dialog = h('div', { 'class': 'sx-modal', role: 'dialog', 'aria-modal': 'true', 'aria-label': title }, [
            h('header', null, [h('h2', { text: title }), btn('', { icon: 'times', small: true, title: t('ui_close'), onclick: close })]),
            h('div', { 'class': 'sx-body' }, body), footer
        ]);
        clear(root);
        add(root, h('div', { 'class': 'sx-modal-back', onclick: function (e) { if (e.target === e.currentTarget) { close(); } } }, dialog));
        document.addEventListener('keydown', esc);
        var first = dialog.querySelector('input, select, textarea, button.sx-btn-primary');
        if (first) { first.focus(); }
        return { close: close, body: dialog.querySelector('.sx-body') };
    }

    function field(label, input, help, err) {
        return h('div', { 'class': 'sx-field' }, [h('label', null, [label, input]), help ? h('span', { 'class': 'sx-help', text: help }) : null, err ? h('span', { 'class': 'sx-err', text: err }) : null]);
    }
    function input(type, value, attrs) { var a = attrs || {}; a.type = type; a.value = value === null || value === undefined ? '' : String(value); return h('input', a); }
    function select(options, value) {
        var s = h('select');
        options.forEach(function (o) { var opt = h('option', { value: o[0], text: o[1] }); if (String(o[0]) === String(value)) { opt.selected = true; } add(s, opt); });
        return s;
    }
    /**
     * A radio group as a fieldset with a legend; `.value` reads and sets the
     * checked option, so it stands in wherever a select did.
     */
    var RADIO_SEQ = 0;
    function radios(legend, options, value, help) {
        var name = 'sx-radio-' + (++RADIO_SEQ);
        var group = h('div', { 'class': 'sx-radios', role: 'presentation' });
        options.forEach(function (o) {
            var input = h('input', { type: 'radio', name: name, value: o[0] });
            if (String(o[0]) === String(value)) { input.checked = true; }
            add(group, h('label', { 'class': 'sx-radio' }, [input, h('span', null, [h('b', { text: o[1] }), o[2] ? h('small', { text: o[2] }) : null])]));
        });
        if (!group.querySelector('input:checked') && group.querySelector('input')) { group.querySelector('input').checked = true; }
        var set = h('fieldset', { 'class': 'sx-field sx-fieldset' }, [h('legend', { text: legend }), group, help ? h('span', { 'class': 'sx-help', text: help }) : null]);
        Object.defineProperty(set, 'value', {
            get: function () { var c = group.querySelector('input:checked'); return c ? c.value : ''; },
            set: function (v) { group.querySelectorAll('input').forEach(function (i) { i.checked = i.value === String(v); }); }
        });
        return set;
    }

    function table(head, rows) {
        return h('div', { 'class': 'sx-scroll' }, h('table', { 'class': 'sx-table' }, [
            h('thead', null, h('tr', null, head.map(function (c) { return h('th', { 'class': c.num ? 'num' : null, text: c.label || c }); }))),
            h('tbody', null, rows)
        ]));
    }
    function empty(iconName, text) { return h('div', { 'class': 'sx-empty' }, [icon(iconName), h('p', { text: text })]); }

    /** "Install sSeo …" with the words linking to sSeo's repository in a new tab; text nodes only. */
    function sseoMissing() {
        var parts = t('ui_sseo_missing').split(':link');
        var link = h('a', { href: CFG.sseoUrl, target: '_blank', rel: 'noopener noreferrer', text: t('ui_sseo_install') });
        return h('div', { 'class': 'sx-alert sx-alert-warn' }, h('span', null, [parts[0], link, parts.slice(1).join(':link')]));
    }

    /** The DataForSEO sign-up: an affiliate link, marked as an ad and disclosed, opened in a new tab. */
    function tryButton() {
        if (!CFG.signup) { return null; }
        return h('span', { 'class': 'sx-try' }, [
            h('span', { 'class': 'sx-try-row' }, [
                h('span', { 'class': 'sx-try-lead', text: t('ui_need_login') }),
                h('a', { 'class': 'sx-btn sx-btn-try', href: CFG.signup, target: '_blank', rel: 'sponsored noopener noreferrer' },
                    [icon('rocket'), ' ', t('ui_try_free'), ' ', h('span', { 'class': 'sx-ad', text: t('ui_ad') })])
            ]),
            h('span', { 'class': 'sx-help sx-small', text: t('ui_affiliate_disclosure') })
        ]);
    }

    // ------------------------------------------------------------------
    // Tabs and polling
    // ------------------------------------------------------------------

    function tabs() {
        var pro = S.boot && S.boot.mode === 'pro';
        var list = [['home', 'lightbulb-o', t('ui_tab_home')], ['jobs', 'tasks', t('ui_tab_jobs')]];
        if (pro) {
            list = list.concat([['matrix', 'th', t('ui_tab_matrix')], ['keywords', 'key', t('ui_tab_keywords')], ['rankings', 'line-chart', t('ui_tab_rankings')]]);
        }
        // Everyone: applied changes are listed and undone here.
        list.push(['issues', 'exclamation-triangle', t('ui_tab_issues')]);
        if (pro) {
            list = list.concat([['ledger', 'money', t('ui_tab_ledger')], ['explorer', 'terminal', t('ui_tab_explorer')]]);
        }
        list.push(['account', 'user', t('ui_tab_account')]);
        list.push(['settings', 'cog', t('ui_tab_settings')]);
        return list;
    }

    /** The account wants attention while the module runs on demo data. */
    function accountNeedsAttention() {
        var key = (S.boot && S.boot.key) || {};
        return !!key.present && key.env === 'demo';
    }

    /** A red notification dot, with its meaning for screen readers. */
    function attentionDot() {
        if (!accountNeedsAttention()) { return null; }
        return h('span', { 'class': 'sx-dot', title: t('ui_account_attention') }, h('span', { 'class': 'sx-sr', text: t('ui_account_attention') }));
    }

    function paintTabs() {
        var bar = clear($('sx-tabs'));
        var active = S.jobs.filter(function (j) { return j.active || j.status === 'awaiting_approval'; }).length;
        tabs().forEach(function (tab) {
            add(bar, h('button', { type: 'button', role: 'tab', 'class': 'sx-tab', 'aria-selected': S.tab === tab[0] ? 'true' : 'false', onclick: function () { go(tab[0]); } },
                [icon(tab[1]), ' ', tab[2], tab[0] === 'jobs' && active ? h('span', { 'class': 'sx-count', text: String(active) }) : null,
                    tab[0] === 'account' ? attentionDot() : null]));
        });
    }

    function go(tab) {
        if (!tabs().some(function (x) { return x[0] === tab; })) { tab = 'home'; }
        S.tab = tab;
        try { history.replaceState(null, '', '#' + tab); } catch (e) { /* framed */ }
        paintTabs();
        var main = clear($('sx-main'));
        (R[tab] || R.home)(main);
    }

    function paintHeader() {
        var b = S.boot;
        $('sx-domain').textContent = b.project.domain;
        var s = clear($('sx-summary'));
        var key = b.key || {};
        var openAccount = function () {
            if (!$('sx-wizard').hidden) { S.wizard = 'closed'; $('sx-wizard').hidden = true; }
            go('account');
        };
        var accountBtn = btn(t('ui_tab_account'), { small: true, icon: 'user', cls: 'sx-open-account', title: t('ui_key_title'), onclick: openAccount });
        add(accountBtn, attentionDot());
        // The data-source label opens the Account tab too: a button dressed as the label.
        var label = key.present ? (key.env === 'demo' ? t('ui_env_demo') : (key.env === 'sandbox' ? t('ui_env_sandbox') : (key.env === 'gateway' ? t('ui_env_gateway') : t('ui_env_prod')))) : t('ui_no_key');
        var kind = !key.present ? 'high' : (key.env === 'prod' ? 'done' : 'medium');
        add(s, h('span', { 'class': 'sx-account' }, [
            accountBtn,
            h('button', { type: 'button', 'class': 'sx-badge sx-badge-link is-' + kind, title: t('ui_key_title'), text: label, onclick: openAccount })
        ]));
        if (key.balance !== null && key.balance !== undefined && (key.env === 'prod' || key.env === 'gateway')) { add(s, h('span', null, [t('ui_balance') + ': ', h('b', { text: money(key.balance, null, key.env === 'gateway' ? 'EUR' : 'USD') })])); }
        add(s, h('span', null, [t('ui_spent_month') + ': ', h('b', { 'class': 'sx-money', text: money(b.budget.spent_month) }), ' / ' + money(b.budget.monthly_cap)]));
    }

    function paintQueue() {
        var q = S.boot.queue || {};
        var box = clear($('sx-queue'));
        var stuck = S.jobs.some(function (j) { return j.status === 'queued' && j.created_at && (Date.now() - new Date(j.created_at).getTime()) > 120000; });
        if (!q.error && !stuck) { return; }
        var alert = h('div', { 'class': 'sx-alert sx-alert-warn' }, [h('b', { text: q.error || t('ui_queue_stuck') })]);
        (q.hints || []).forEach(function (hint) { add(alert, h('div', null, [hint.message + ' ', h('code', { text: hint.command })])); });
        add(box, alert);
    }

    function refreshJobs() {
        return api('GET', '/jobs').then(function (r) {
            if (!r || r.ok === false) { return; }
            var before = {};
            S.jobs.forEach(function (j) { before[j.uuid] = j.status; });
            S.jobs = r.jobs || [];
            var finished = S.jobs.some(function (j) { return before[j.uuid] && before[j.uuid] !== j.status && j.finished; });
            paintTabs();
            paintQueue();
            if (finished) { live(t('ui_job_finished')); reloadBoot(); }
            else if (S.tab === 'jobs') { go('jobs'); }
            kick();
        });
    }

    function kick() {
        if (S.timer) { clearTimeout(S.timer); S.timer = null; }
        var busy = S.jobs.some(function (j) { return j.active; });
        if (busy) { S.timer = setTimeout(refreshJobs, POLL_MS); }
    }

    function reloadBoot() {
        return api('GET', '/bootstrap').then(function (r) {
            if (!r || r.ok === false) { notice($('sx-page-error'), 'error', errMsg(r)); return; }
            S.boot = r;
            S.jobs = r.jobs || [];
            if (r.csrf_token) { CFG.csrf = r.csrf_token; }
            paintHeader();
            paintQueue();
            if (needsWizard()) { R.wizard(); } else { $('sx-wizard').hidden = true; go(S.tab); }
            kick();
        });
    }

    function needsWizard() {
        var b = S.boot;
        return S.wizard !== 'closed' && (!b.key.present || (!b.state.ever_ran && !S.jobs.length));
    }

    /** Price a job, show the price, and start it on a click. */
    function runJob(kind, options, after) {
        options = options || {};
        api('POST', '/jobs/estimate', { kind: kind, options: options }).then(function (r) {
            if (!r || r.ok === false) { modal(t('kind_' + kind), [h('div', { 'class': 'sx-alert sx-alert-error', text: errMsg(r) })]); return; }
            var e = r.estimate;
            var cur = r.currency;
            var body = [
                h('p', { text: t('ui_kind_help_' + kind) }),
                h('p', null, [t('ui_estimate') + ': ', h('b', { text: estimateText(e, cur) })]),
                e.unknown ? h('div', { 'class': 'sx-alert sx-alert-warn', text: t('ui_estimate_unknown_help') }) : null,
                r.steps && r.steps.length ? table([t('ui_step'), t('ui_endpoint'), { label: t('ui_estimate'), num: true }], r.steps.map(function (s) {
                    return h('tr', null, [h('td', { text: s.name }), h('td', null, h('code', { text: s.endpoint })), h('td', { 'class': 'num', text: estimateText(s.estimate, cur) })]);
                })) : null
            ];
            var m;
            var start = btn(t('ui_start'), { primary: true, icon: 'play', disabled: !S.boot.flags.may_spend, onclick: function (b) {
                b.disabled = true;
                api('POST', '/jobs', { kind: kind, options: options }).then(function (res) {
                    if (!res || res.ok === false) { add(m.body, h('div', { 'class': 'sx-alert sx-alert-error', text: errMsg(res) })); b.disabled = false; return; }
                    m.close();
                    live(t('ui_job_started'));
                    refreshJobs().then(function () { if (after) { after(res.job); } else { go('jobs'); } });
                });
            } });
            m = modal(t('kind_' + kind), body, [start]);
        });
    }

    function jobAction(job, action) {
        return api('POST', '/jobs/' + encodeURIComponent(job.uuid) + '/' + action, {}).then(function (r) {
            if (!r || r.ok === false) { notice($('sx-page-error'), 'error', errMsg(r)); }
            return refreshJobs();
        });
    }

    /** Back from the gateway's consent page: `?gateway=connected|failed&message=`, shown once and removed from the address. */
    function connectFlash() {
        var query;
        try { query = new URLSearchParams(location.search); } catch (e) { return; }
        var flag = query.get('gateway');
        if (!flag) { return; }
        var msg = query.get('message') || '';
        try { history.replaceState(null, '', location.pathname + location.hash); } catch (e) { /* framed */ }
        setTimeout(function () { notice($('sx-page-error'), flag === 'connected' ? 'ok' : 'error', msg || (flag === 'connected' ? t('ui_gateway_connected') : t('error_unknown'))); }, 0);
    }

    function start() {
        var hash = String(location.hash || '').replace('#', '');
        if (hash) { S.tab = hash; }
        connectFlash();
        reloadBoot();
    }
@endverbatim
