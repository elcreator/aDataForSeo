@verbatim
    // ------------------------------------------------------------------
    // Account: the DataForSEO key (also opened from the header badge)
    // ------------------------------------------------------------------

    /** Demo data is a way in, not a way back: once a real login is connected it is no longer offered. */
    function signedIn(key) { return !!(key && key.present && key.env && key.env !== 'demo'); }

    function envChoices(key) {
        var list = [['prod', t('ui_env_prod'), t('ui_env_prod_help')], ['sandbox', t('ui_env_sandbox'), t('ui_env_sandbox_help')]];
        if (!signedIn(key)) { list.push(['demo', t('ui_env_demo'), t('ui_env_demo_help')]); }
        return list;
    }

    /** How this site pays: its own DataForSEO account or the ai.artur.work balance. Both keys can be kept; this chooses. */
    function billingPanel() {
        var b = S.boot;
        var billing = radios(t('ui_billing_title'), [['own', t('ui_billing_own'), t('ui_billing_own_help')], ['gateway', t('ui_billing_gateway'), t('ui_billing_gateway_help')]], b.billing || 'own');
        var msg = h('div', { 'class': 'sx-small' });
        add(billing, msg);
        billing.addEventListener('change', function () {
            var chosen = billing.value;
            api('POST', '/project', { billing: chosen }).then(function (r) {
                if (!r || r.ok === false) { notice(msg, 'error', errMsg(r)); billing.value = S.boot.billing || 'own'; return; }
                notice(msg, 'ok', t('ui_billing_saved'));
                reloadBoot();
            });
        });
        if (!b.flags.may_manage) { billing.querySelectorAll('input').forEach(function (i) { i.disabled = true; }); }

        return h('div', { 'class': 'sx-panel' }, [h('h2', { text: t('ui_billing_title') }), billing, isGateway() ? h('p', { 'class': 'sx-muted sx-small', text: t('ui_gateway_limits') }) : null]);
    }

    /** The ai.artur.work key: connected (no key to copy) or pasted, with its balance and where to top it up. */
    function gatewayPanel() {
        var b = S.boot;
        var key = (b.keys && b.keys.gateway) || {};
        var scope = b.flags.may_manage ? 'site' : 'user';
        var pasted = input('password', '', { autocomplete: 'off', placeholder: key.present ? t('ui_password_keep') : '' });
        var msg = h('div');
        var topUp = gatewayLink(key.top_up_url, key.gateway_url);
        var facts = [];
        if (key.present) {
            facts.push(t('ui_gateway_present', { key: key.login }));
            if (key.verified_at) { facts.push(t('ui_verified', { when: when(key.verified_at) })); }
            if (key.balance !== null && key.balance !== undefined) { facts.push(t('ui_balance') + ': ' + money(key.balance, null, 'EUR')); }
            if (key.cap_eur !== null && key.cap_eur !== undefined) { facts.push(t('ui_gateway_cap', { amount: money(key.cap_eur, null, 'EUR') })); }
        }

        return h('div', { 'class': 'sx-panel' }, [
            h('h2', { text: t('ui_gateway_title') }),
            h('p', { 'class': 'sx-muted', text: key.present ? facts.join(' · ') : t('ui_gateway_missing') }),
            h('p', { 'class': 'sx-muted sx-small', text: t('ui_gateway_connect_help') }),
            h('div', { 'class': 'sx-toolbar' }, [
                h('a', { 'class': 'sx-btn sx-btn-primary', href: BASE + '/gateway/connect?scope=' + scope, target: '_top' }, [icon('key'), ' ', t('ui_gateway_connect')]),
                topUp ? h('a', { 'class': 'sx-btn', href: topUp, target: '_blank', rel: 'noopener noreferrer' }, [icon('money'), ' ', t('ui_gateway_topup')]) : null
            ]),
            h('div', { 'class': 'sx-form', style: 'margin-top:.8rem' }, [field(t('ui_gateway_paste') + ': ' + t('field_gateway_key'), pasted, t('ui_gateway_key_help'))]),
            msg,
            h('div', { 'class': 'sx-toolbar' }, [
                btn(t('ui_save_verify'), { primary: true, icon: 'check', disabled: !b.flags.may_spend, onclick: function (bt) {
                    bt.disabled = true;
                    api('POST', '/gateway/key', { scope: scope, key: pasted.value, verify: true, use: false }).then(function (r) {
                        bt.disabled = false;
                        if (!r || r.ok === false) { notice(msg, 'error', errMsg(r)); return; }
                        notice(msg, 'ok', t('ui_key_saved'));
                        reloadBoot();
                    });
                } }),
                key.present ? btn(t('ui_verify'), { icon: 'refresh', onclick: function () {
                    api('POST', '/gateway/verify', {}).then(function (r) { notice(msg, r && r.ok !== false ? 'ok' : 'error', r && r.ok !== false ? t('ui_key_verified') : errMsg(r)); if (r && r.ok !== false) { reloadBoot(); } });
                } }) : null,
                key.present && b.flags.may_manage ? btn(t('ui_gateway_remove'), { danger: true, onclick: function () {
                    api('POST', '/key/delete', { scope: key.scope || scope, provider: 'gateway' }).then(function (r) { if (!r || r.ok === false) { notice(msg, 'error', errMsg(r)); return; } reloadBoot(); });
                } }) : null
            ])
        ]);
    }

    R.account = function (main) {
        var b = S.boot;
        // The DataForSEO panel is always about the site's own account, whichever way the site pays now.
        var key = (b.keys && b.keys.own) || b.key || {};
        add(main, billingPanel());
        add(main, gatewayPanel());
        var env = radios(t('field_env'), envChoices(key), key.env || 'prod');
        var login = input('text', '', { placeholder: key.login || '', autocomplete: 'username' });
        var password = input('password', '', { autocomplete: 'new-password', placeholder: key.present && key.env !== 'demo' ? t('ui_password_keep') : '' });
        var scope = radios(t('field_scope'), [['site', t('ui_scope_site'), t('ui_scope_site_help')], ['user', t('ui_scope_user'), t('ui_scope_user_help')]], key.scope || 'site');
        // Demo data needs no login: the fields show only for a real account.
        var credentials = h('div', { 'class': 'sx-form', style: 'margin-top:.8rem' }, [field(t('field_login'), login), field(t('field_password'), password, t('ui_password_help'))]);
        var syncCredentials = function () { credentials.hidden = env.value === 'demo'; };
        env.addEventListener('change', syncCredentials);
        syncCredentials();
        var keyMsg = h('div');
        add(main, h('div', { 'class': 'sx-panel' }, [
            h('h2', { text: t('ui_key_title') }),
            h('p', { 'class': 'sx-muted' }, [key.present ? t('ui_key_present', { login: key.login || t('ui_env_demo'), env: key.env }) : t('ui_key_missing'),
                key.verified_at ? ' · ' + t('ui_verified', { when: when(key.verified_at) }) : '']),
            h('div', { 'class': 'sx-form' }, [env, scope]),
            credentials,
            keyMsg,
            h('div', { 'class': 'sx-toolbar' }, [
                btn(t('ui_save_verify'), { primary: true, icon: 'check', disabled: !b.flags.may_spend, onclick: function (bt) {
                    bt.disabled = true;
                    api('POST', '/key', { scope: scope.value, env: env.value, login: login.value || (key.env !== 'demo' ? '' : ''), password: password.value, verify: true }).then(function (r) {
                        bt.disabled = false;
                        if (!r || r.ok === false) { notice(keyMsg, 'error', errMsg(r)); return; }
                        if (r.demo_forgotten) {
                            // The demo's jobs, findings and pages are gone: filters and drafts that pointed at them too.
                            S.data = {};
                            S.jobs = [];
                            notice($('sx-page-error'), 'ok', t('ui_demo_forgotten'));
                        } else {
                            notice(keyMsg, 'ok', t('ui_key_saved'));
                        }
                        reloadBoot();
                    });
                } }),
                key.present ? btn(t('ui_verify'), { icon: 'refresh', onclick: function () { api('POST', '/key/verify', {}).then(function (r) { notice(keyMsg, r && r.ok !== false ? 'ok' : 'error', r && r.ok !== false ? t('ui_key_verified') : errMsg(r)); if (r && r.ok !== false) { reloadBoot(); } }); } }) : null,
                h('span', { 'class': 'sx-grow' }),
                tryButton()
            ])
        ]));
    };

    // ------------------------------------------------------------------
    // Settings: project, budgets, schedules, weights
    // ------------------------------------------------------------------

    R.settings = function (main) {
        var b = S.boot;
        var manage = b.flags.may_manage;
        var p = b.project;
        var key = b.key || {};

        if (!manage) {
            add(main, h('div', { 'class': 'sx-alert sx-alert-info', text: t('ui_settings_need_manage') }));
            return;
        }

        // Project
        var market = (p.markets || [])[0] || { country: 'US', languages: ['en'], geo: [] };
        var mode = radios(t('field_mode'), [['beginner', t('ui_mode_beginner'), t('ui_mode_beginner_help')], ['pro', t('ui_mode_pro'), t('ui_mode_pro_help')]], p.mode);
        // The mode is a view switch: it applies at once, and only the tabs change, so other edits on this form are kept.
        var modeMsg = h('div', { 'class': 'sx-small' });
        add(mode, modeMsg);
        mode.addEventListener('change', function () {
            var chosen = mode.value;
            api('POST', '/project', { mode: chosen }).then(function (r) {
                if (!r || r.ok === false) { notice(modeMsg, 'error', errMsg(r)); mode.value = S.boot.mode; return; }
                S.boot.mode = r.project.mode;
                S.boot.project.mode = r.project.mode;
                paintTabs();
                notice(modeMsg, 'ok', t('ui_mode_saved'));
                live(t('ui_mode_saved'));
            });
        });
        var domain = input('text', p.domain);
        var country = input('text', market.country, { maxlength: 2 });
        var languages = input('text', (market.languages || []).join(', '));
        var places = h('textarea', { rows: 4, text: (market.geo || []).map(placeLine).join('\n') });
        var siteLanguages = input('text', (p.site_languages || []).join(', '));
        var competitors = input('text', (p.competitors || []).join(', '));
        var seeds = h('textarea', { rows: 3, text: ((p.settings || {}).seeds || []).join('\n') });
        var brands = input('text', ((p.settings || {}).brand_terms || []).join(', '));
        var crawl = input('number', (p.settings || {}).crawl_pages || 200, { min: '10', max: '10000' });
        var own = p.budget.own || {};
        var monthly = input('number', own.monthly_cap === null ? '' : own.monthly_cap, { step: '0.5', placeholder: String(p.budget.monthly_cap) });
        var jobCap = input('number', own.job_cap === null ? '' : own.job_cap, { step: '0.1', placeholder: String(p.budget.job_cap) });
        var approve = input('number', own.approve_above === null ? '' : own.approve_above, { step: '0.1', placeholder: String(p.budget.approve_above) });
        var sch = p.schedule || {};
        var schedule = {};
        ['coverage', 'audit', 'research', 'track'].forEach(function (k) { schedule[k] = input('number', sch[k] || 0, { min: '0', max: '365' }); });
        var weights = (p.settings || {}).weights || {};
        var w = {};
        ['w_amb', 'w_origin', 'bonus', 't', 'locale_factor', 'k'].forEach(function (k) { w[k] = input('number', weights[k] === undefined ? '' : weights[k], { step: '0.05' }); });
        var msg = h('div');

        add(main, h('div', { 'class': 'sx-panel' }, [
            h('h2', { text: t('ui_project_title') }),
            h('div', { 'class': 'sx-form' }, [
                mode, field(t('field_domain'), domain),
                field(t('field_country'), country), field(t('field_languages'), languages, t('ui_languages_help')),
                field(t('field_site_languages'), siteLanguages, t('ui_site_languages_help')), field(t('field_competitors'), competitors, t('ui_competitors_help')),
                field(t('field_brand_terms'), brands, t('ui_brand_help')), field(t('field_crawl_pages'), crawl, t('ui_crawl_help'))
            ]),
            field(t('field_places'), places, t('ui_places_help')),
            field(t('field_seeds'), seeds, t('ui_seeds_help')),
            h('h3', { text: t('ui_budget_title') }),
            h('div', { 'class': 'sx-form' }, [field(t('field_monthly_cap'), monthly, t('ui_monthly_cap_help')), field(t('field_job_cap'), jobCap, t('ui_job_cap_help')), field(t('field_approve_above'), approve, t('ui_approve_above_help'))]),
            h('h3', { text: t('ui_schedule_title') }),
            h('p', { 'class': 'sx-muted sx-small', text: t('ui_schedule_help') }),
            h('div', { 'class': 'sx-form' }, Object.keys(schedule).map(function (k) { return field(t('kind_' + k), schedule[k], t('ui_days')); })),
            h('h3', { text: t('ui_weights_title') }),
            h('p', { 'class': 'sx-muted sx-small', text: t('ui_weights_help') }),
            h('div', { 'class': 'sx-form' }, Object.keys(w).map(function (k) { return field(k, w[k]); })),
            msg,
            h('div', { 'class': 'sx-toolbar' }, [btn(t('ui_save'), { primary: true, icon: 'save', onclick: function (bt) {
                bt.disabled = true;
                var weightValues = {};
                Object.keys(w).forEach(function (k) { if (w[k].value !== '') { weightValues[k] = w[k].value; } });
                var scheduleValues = {};
                Object.keys(schedule).forEach(function (k) { scheduleValues[k] = Number(schedule[k].value) || 0; });
                var c = (S.boot.countries || {})[country.value.toLowerCase()] || {};
                api('POST', '/project', {
                    mode: mode.value, domain: domain.value,
                    markets: [{ country: country.value, location_code: market.country.toUpperCase() === country.value.toUpperCase() ? market.location_code : c.location_code, languages: languages.value, geo: parsePlaces(places.value) }],
                    competitors: competitors.value, monthly_cap: monthly.value, job_cap: jobCap.value, approve_above: approve.value,
                    schedule: scheduleValues,
                    settings: { site_languages: siteLanguages.value, seeds: seeds.value, brand_terms: brands.value, crawl_pages: crawl.value, weights: weightValues }
                }).then(function (r) {
                    bt.disabled = false;
                    if (!r || r.ok === false) { notice(msg, 'error', errMsg(r)); return; }
                    notice(msg, 'ok', t('ui_saved'));
                    reloadBoot();
                });
            } }), btn(t('ui_open_wizard'), { icon: 'magic', onclick: function () { S.wizard = null; S.data.wiz = { step: 1, env: key.env || 'demo', login: '', password: '' }; R.wizard(); } })])
        ]));

        // Queue
        var q = b.queue || {};
        add(main, h('div', { 'class': 'sx-panel' }, [
            h('h2', { text: t('ui_queue_title') }),
            h('p', null, [t('ui_queue_driver') + ': ', h('b', { text: q.driver || '—' }), ' (' + (q.configured || 'auto') + ')']),
            h('ul', null, (q.hints || []).map(function (hint) { return h('li', null, [hint.message + ' ', h('code', { text: hint.command })]); }))
        ]));
    };
@endverbatim
