@verbatim
    // ------------------------------------------------------------------
    // Wizard: key or demo → where are your customers → budget → run
    // ------------------------------------------------------------------

    R.wizard = function () {
        var w = S.data.wiz || (S.data.wiz = { step: S.boot.key.present ? 2 : 1, env: signedIn(S.boot.key) ? S.boot.key.env : 'demo', login: '', password: '' });
        if (signedIn(S.boot.key) && w.env === 'demo') { w.env = S.boot.key.env; }
        var box = clear($('sx-wizard'));
        box.hidden = false;
        clear($('sx-tabs'));
        clear($('sx-main'));
        var steps = h('div', { 'class': 'sx-steps' }, [1, 2, 3].map(function (n) { return h('span', { 'class': w.step === n ? 'is-on' : null, text: t('ui_wiz_step' + n) }); }));
        var panel = h('div', { 'class': 'sx-panel sx-wizard' }, [h('h2', { text: t('ui_wiz_title') }), steps]);
        var err = h('div');
        add(box, panel);

        var skip = btn(t('ui_wiz_skip'), { small: true, onclick: function () { S.wizard = 'closed'; box.hidden = true; go('home'); } });

        if (w.step === 1) {
            var choice = h('div', { 'class': 'sx-choice' });
            [['demo', t('ui_wiz_demo'), t('ui_wiz_demo_help')], ['prod', t('ui_wiz_own'), t('ui_wiz_own_help')], ['sandbox', t('ui_wiz_sandbox'), t('ui_wiz_sandbox_help')]].filter(function (c) {
                return c[0] !== 'demo' || !signedIn(S.boot.key);
            }).forEach(function (c) {
                add(choice, h('button', { type: 'button', 'class': w.env === c[0] ? 'is-on' : null, onclick: function () { w.env = c[0]; R.wizard(); } }, [h('b', { text: c[1] }), h('span', { 'class': 'sx-muted sx-small', text: c[2] })]));
            });
            add(panel, choice);
            var login = input('text', w.login, { autocomplete: 'username' });
            var password = input('password', '', { autocomplete: 'new-password' });
            if (w.env !== 'demo') {
                add(panel, h('div', { 'class': 'sx-form' }, [field(t('field_login'), login, t('ui_login_help')), field(t('field_password'), password, t('ui_password_help'))]));
                add(panel, h('p', null, tryButton()));
            }
            add(panel, err);
            add(panel, h('div', { 'class': 'sx-toolbar' }, [btn(t('ui_next'), { primary: true, icon: 'arrow-right', disabled: !S.boot.flags.may_manage, onclick: function (b) {
                b.disabled = true;
                api('POST', '/key', { scope: 'site', env: w.env, login: login.value, password: password.value, verify: true }).then(function (r) {
                    b.disabled = false;
                    if (!r || r.ok === false) { notice(err, 'error', errMsg(r)); return; }
                    w.step = 2;
                    reloadBoot();
                });
            } }), h('span', { 'class': 'sx-grow' }), skip]));
            if (!S.boot.flags.may_manage) { add(panel, h('div', { 'class': 'sx-alert sx-alert-warn', text: t('ui_wiz_need_manage') })); }
            return;
        }

        var project = S.boot.project;
        var market = (project.markets || [])[0] || { country: 'US', languages: ['en'], geo: [] };

        if (w.step === 2) {
            var countries = Object.keys(S.boot.countries || {}).sort().map(function (c) { return [c.toUpperCase(), c.toUpperCase()]; });
            var country = select(countries, market.country);
            var languages = input('text', (market.languages || []).join(', '));
            var places = h('textarea', { rows: 4, text: (market.geo || []).map(function (g) { return placeLine(g); }).join('\n') });
            country.addEventListener('change', function () {
                var c = (S.boot.countries || {})[country.value.toLowerCase()];
                if (c) { languages.value = c.language; }
            });
            add(panel, h('p', { text: t('ui_wiz_markets_help') }));
            add(panel, h('div', { 'class': 'sx-form' }, [field(t('field_country'), country), field(t('field_languages'), languages, t('ui_languages_help'))]));
            add(panel, field(t('field_places'), places, t('ui_places_help')));
            add(panel, err);
            add(panel, h('div', { 'class': 'sx-toolbar' }, [btn(t('ui_back'), { onclick: function () { w.step = 1; R.wizard(); } }), btn(t('ui_next'), { primary: true, icon: 'arrow-right', onclick: function () {
                var c = (S.boot.countries || {})[country.value.toLowerCase()] || {};
                var payload = { markets: [{ country: country.value, location_code: c.location_code, languages: languages.value, geo: parsePlaces(places.value) }] };
                api('POST', '/project', payload).then(function (r) {
                    if (!r || r.ok === false) { notice(err, 'error', errMsg(r)); return; }
                    w.step = 3;
                    reloadBoot();
                });
            } }), h('span', { 'class': 'sx-grow' }), skip]));
            return;
        }

        // Step 3: budget and the first run (coverage + audit).
        var cap = input('number', S.boot.budget.monthly_cap, { step: '0.5', min: '0' });
        var approve = input('number', S.boot.budget.approve_above, { step: '0.1', min: '0' });
        var estimate = h('p', { 'class': 'sx-muted', text: t('ui_estimating') });
        add(panel, h('p', { text: t('ui_wiz_budget_help') }));
        add(panel, h('div', { 'class': 'sx-form' }, [field(t('field_monthly_cap'), cap, t('ui_monthly_cap_help')), field(t('field_approve_above'), approve, t('ui_approve_above_help'))]));
        add(panel, estimate);
        add(panel, err);
        var total = null;
        Promise.all(['coverage', 'audit'].map(function (k) { return api('POST', '/jobs/estimate', { kind: k, options: {} }); })).then(function (rs) {
            var unknown = rs.some(function (r) { return !r || r.ok === false || r.estimate.unknown; });
            total = unknown ? null : rs.reduce(function (s, r) { return s + Number(r.estimate.high || r.estimate.amount); }, 0);
            estimate.textContent = t('ui_wiz_first_run', { price: total === null ? t('ui_price_unknown') : money(total) });
        });
        add(panel, h('div', { 'class': 'sx-toolbar' }, [btn(t('ui_back'), { onclick: function () { w.step = 2; R.wizard(); } }), btn(t('ui_wiz_run'), { primary: true, icon: 'play', disabled: !S.boot.flags.may_spend, onclick: function (b) {
            b.disabled = true;
            api('POST', '/project', { monthly_cap: cap.value, approve_above: approve.value }).then(function (r) {
                if (!r || r.ok === false) { notice(err, 'error', errMsg(r)); b.disabled = false; return null; }
                return Promise.all(['coverage', 'audit'].map(function (k) {
                    return api('POST', '/jobs', { kind: k, options: {} }).then(function (res) {
                        // The price was on screen when the manager clicked: that click approves it.
                        if (res && res.ok !== false && res.job.status === 'awaiting_approval') { return api('POST', '/jobs/' + res.job.uuid + '/approve', {}); }
                        return res;
                    });
                }));
            }).then(function (results) {
                if (!results) { return; }
                var bad = results.filter(function (r) { return !r || r.ok === false; });
                if (bad.length) { notice(err, 'error', errMsg(bad[0])); b.disabled = false; return; }
                S.wizard = 'closed';
                $('sx-wizard').hidden = true;
                S.tab = 'home';
                reloadBoot();
            });
        } }), h('span', { 'class': 'sx-grow' }), skip]));
    };

    function placeLine(g) {
        var spell = [];
        Object.keys(g.spellings || {}).forEach(function (lang) { (g.spellings[lang] || []).forEach(function (s) { spell.push(lang + '=' + s); }); });
        return g.name + (spell.length ? ': ' + spell.join(', ') : '');
    }
    /** "Praha: en=Prague, uk=Прага" → {name, spellings: {en: [...], uk: [...]}} */
    function parsePlaces(text) {
        return String(text || '').split(/\n/).map(function (line) {
            var parts = line.split(':');
            var name = parts.shift().trim();
            if (!name) { return null; }
            var spellings = {};
            parts.join(':').split(',').forEach(function (item) {
                var m = /^\s*([a-z]{2})\s*=\s*(.+?)\s*$/.exec(item);
                if (m) { (spellings[m[1]] = spellings[m[1]] || []).push(m[2]); }
            });
            return { name: name, spellings: spellings };
        }).filter(Boolean);
    }

    // ------------------------------------------------------------------
    // Home: cards
    // ------------------------------------------------------------------

    R.home = function (main) {
        add(main, runBar());
        var holder = h('div', null, h('p', { 'class': 'sx-muted', text: t('ui_loading') }));
        add(main, holder);
        api('GET', '/cards').then(function (r) {
            clear(holder);
            if (!r || r.ok === false) { notice(holder, 'error', errMsg(r)); return; }
            S.cards = r.cards || [];
            var state = r.state || {};
            if (!S.cards.length) {
                add(holder, empty(state.running ? 'hourglass-half' : (state.ever_ran ? 'check-circle' : 'search'),
                    state.running ? t('ui_empty_running') : (state.ever_ran ? t('ui_empty_clear') : t('ui_empty_never'))));
                return;
            }
            add(holder, h('div', { 'class': 'sx-cards' }, S.cards.map(card)));
        });
    };

    function runBar() {
        var done = (S.boot.state && S.boot.state.kinds_done) || [];
        var bar = h('div', { 'class': 'sx-panel' }, [h('h2', { text: t('ui_run_title') })]);
        var row = h('div', { 'class': 'sx-runbar' });
        [['coverage', 'eye'], ['audit', 'stethoscope'], ['research', 'search'], ['difficulty', 'signal'], ['track', 'line-chart'], ['competitors', 'users']].forEach(function (k) {
            var needsResearch = (k[0] === 'difficulty' || k[0] === 'track' || k[0] === 'competitors') && done.indexOf('research') < 0;
            add(row, btn(t('kind_' + k[0]), { icon: k[1], disabled: needsResearch || !S.boot.flags.may_spend, title: needsResearch ? t('ui_needs_research') : t('ui_kind_help_' + k[0]), onclick: function () { runJob(k[0]); } }));
        });
        add(bar, row);
        return bar;
    }

    function card(c) {
        var el = h('article', { 'class': 'sx-card is-' + c.severity }, [
            h('div', { 'class': 'sx-toolbar', style: 'margin:0' }, [badge(t('sev_' + c.severity), c.severity), badge(t('effort_' + c.effort), c.effort), c.count > 1 ? badge('×' + c.count) : null]),
            h('h3', { text: c.title }),
            h('p', { 'class': 'sx-why', text: c.why })
        ]);
        if (c.type === 'target') {
            var tg = c.target;
            add(el, h('p', { 'class': 'sx-small' }, [t('ui_demand') + ': ', h('b', { text: num(tg.demand_real) }), tg.ratio && tg.ratio > 1.05 ? ' (' + t('ui_raw_sum', { n: num(tg.demand_raw) }) + ')' : '', ' · ', h('code', { text: tg.proposed_url || '' })]));
            add(el, h('div', { 'class': 'sx-card-actions' }, [btn(t('ui_create_draft'), { primary: true, icon: 'file-o', onclick: function () { draftDialog(tg); } })]));
            return el;
        }
        var list = h('ul');
        (c.items || []).forEach(function (i) { add(list, h('li', null, [i.url || '—', i.document_id ? h('span', { 'class': 'sx-muted', text: ' #' + i.document_id }) : null])); });
        if (c.count > (c.items || []).length) { add(list, h('li', { 'class': 'sx-muted', text: t('ui_and_more', { n: c.count - c.items.length }) })); }
        add(el, list);
        var first = (c.items || [])[0];
        add(el, h('div', { 'class': 'sx-card-actions' }, [
            first ? btn(c.fix === 'manual' ? t('ui_how_to_fix') : t('ui_fix'), { primary: c.fix !== 'manual', icon: c.fix === 'manual' ? 'info-circle' : 'magic', onclick: function () { fixDialog(c, 0); } }) : null,
            first ? btn(t('ui_ignore'), { icon: 'eye-slash', onclick: function () { Promise.all(c.items.map(function (i) { return api('POST', '/issues/' + i.id + '/ignore', { ignore: true }); })).then(function () { go('home'); }); } }) : null
        ]));
        return el;
    }

    /** One finding at a time: preview what changes, apply, next. */
    function fixDialog(c, index) {
        var item = c.items[index];
        if (!item) { go('home'); return; }
        api('POST', '/issues/' + item.id + '/preview', {}).then(function (r) {
            var body = [h('p', null, [h('b', { text: item.url })])];
            if (!r || r.ok === false) { body.push(h('div', { 'class': 'sx-alert sx-alert-error', text: errMsg(r) })); }
            var inputs = {};
            if (r && r.ok !== false) {
                if (r.instructions && (r.fix === 'manual' || !r.available)) { body.push(h('div', { 'class': 'sx-alert sx-alert-info', text: r.instructions })); }
                if (r.fix !== 'manual' && !r.available) { body.push(sseoMissing()); }
                (r.changes || []).forEach(function (ch) {
                    if (ch.target === 'manual') { return; }
                    var edit = input('text', ch.after);
                    inputs[ch.field] = edit;
                    body.push(h('div', { 'class': 'sx-diff' }, [h('span', { 'class': 'sx-muted', text: t('field_' + ch.field) === 'field_' + ch.field ? ch.field : t('field_' + ch.field) }), h('div', null, [ch.before ? h('div', { 'class': 'sx-before', text: ch.before }) : h('div', { 'class': 'sx-muted', text: t('ui_empty_value') }), edit])]));
                });
            }
            var m;
            var actions = [];
            var next = function () { m.close(); if (index + 1 < c.items.length) { fixDialog(c, index + 1); } else { go('home'); } };
            if (r && r.ok !== false && (r.fix === 'manual' || r.available)) {
                actions.push(btn(r.fix === 'manual' ? t('ui_mark_done') : t('ui_apply'), { primary: true, icon: 'check', onclick: function (b) {
                    b.disabled = true;
                    var payload = { fields: {} };
                    Object.keys(inputs).forEach(function (f) { if (r.fix === 'redirect') { payload.new_url = inputs[f].value; } else { payload.fields[f] = inputs[f].value; } });
                    api('POST', '/issues/' + item.id + '/apply', payload).then(function (res) {
                        if (!res || res.ok === false) { add(m.body, h('div', { 'class': 'sx-alert sx-alert-error', text: errMsg(res) })); b.disabled = false; return; }
                        live(t('ui_applied'));
                        next();
                    });
                } }));
            }
            if (c.items.length > 1) { actions.push(btn(t('ui_skip'), { onclick: next })); }
            m = modal(c.title + (c.items.length > 1 ? ' (' + (index + 1) + '/' + c.items.length + ')' : ''), body, actions);
        });
    }

    function draftDialog(tg) {
        var parent = input('number', 0, { min: '0' });
        var title = input('text', '');
        var m = modal(t('ui_create_draft'), [
            h('p', { text: t('ui_draft_help') }),
            h('p', null, h('code', { text: tg.proposed_url || '' })),
            field(t('field_parent'), parent, t('ui_parent_help')),
            field(t('field_pagetitle'), title, t('ui_pagetitle_help'))
        ], [btn(t('ui_create_draft'), { primary: true, icon: 'file-o', onclick: function (b) {
            b.disabled = true;
            api('POST', '/targets/' + tg.id + '/apply', { kind: 'draft', parent: Number(parent.value) || 0, pagetitle: title.value }).then(function (r) {
                if (!r || r.ok === false) { add(m.body, h('div', { 'class': 'sx-alert sx-alert-error', text: errMsg(r) })); b.disabled = false; return; }
                m.close();
                live(t('ui_draft_created', { id: r.target.document_id }));
                go(S.tab);
            });
        } })]);
    }

    // ------------------------------------------------------------------
    // Jobs
    // ------------------------------------------------------------------

    R.jobs = function (main) {
        add(main, runBar());
        if (!S.jobs.length) { add(main, empty('tasks', t('ui_no_jobs'))); return; }
        add(main, h('div', { 'class': 'sx-panel' }, table([t('ui_job'), t('ui_status'), t('ui_progress'), { label: t('ui_estimate'), num: true }, { label: t('ui_spent'), num: true }, t('ui_when'), ''], S.jobs.map(function (j) {
            var actions = h('div', { 'class': 'sx-toolbar', style: 'margin:0' });
            if (j.status === 'awaiting_approval' && S.boot.flags.may_spend) { add(actions, btn(t('ui_approve'), { small: true, primary: true, icon: 'check', onclick: function () { jobAction(j, 'approve'); } })); }
            if (j.active || j.status === 'awaiting_approval') { add(actions, btn(t('ui_cancel'), { small: true, danger: true, onclick: function () { jobAction(j, 'cancel'); } })); }
            if (j.status === 'failed' || j.status === 'cancelled') { add(actions, btn(t('ui_retry'), { small: true, icon: 'refresh', onclick: function () { jobAction(j, 'retry'); } })); }
            add(actions, btn(t('ui_details'), { small: true, onclick: function () { jobDetails(j); } }));
            return h('tr', null, [
                h('td', null, [h('b', { text: j.kind_label }), j.trigger !== 'manual' ? h('span', { 'class': 'sx-muted', text: ' · ' + j.trigger }) : null]),
                h('td', null, [badge(t('status_' + j.status), j.status), j.error ? h('div', { 'class': 'sx-small sx-down', text: j.error }) : null, j.status === 'waiting' && j.wake_at ? h('div', { 'class': 'sx-small sx-muted', text: t('ui_wakes', { when: when(j.wake_at) }) }) : null]),
                h('td', null, h('div', { 'class': 'sx-progress', title: j.progress + '%' }, h('div', { style: 'width:' + j.progress + '%' }))),
                h('td', { 'class': 'num', text: j.estimate === null ? t('ui_price_unknown') : money(j.estimate) }),
                h('td', { 'class': 'num', text: money(j.spent) }),
                h('td', { 'class': 'sx-small', text: when(j.created_at) }),
                h('td', null, actions)
            ]);
        }))));
    };

    function jobDetails(j) {
        api('GET', '/jobs/' + encodeURIComponent(j.uuid)).then(function (r) {
            if (!r || r.ok === false) { modal(j.kind_label, [h('div', { 'class': 'sx-alert sx-alert-error', text: errMsg(r) })]); return; }
            var job = r.job;
            modal(job.kind_label, [
                table([t('ui_step'), t('ui_status'), t('ui_endpoint'), { label: t('ui_estimate'), num: true }, { label: t('ui_spent'), num: true }], (job.steps || []).map(function (s) {
                    return h('tr', null, [h('td', { text: s.name }), h('td', null, [badge(t('status_' + s.status), s.status), s.error ? h('div', { 'class': 'sx-small sx-down', text: s.error }) : null]),
                        h('td', null, s.endpoint ? h('code', { text: s.endpoint }) : '—'), h('td', { 'class': 'num', text: s.estimate === null ? (s.kind === 'post' || s.kind === 'live' ? t('ui_price_unknown') : '—') : money(s.estimate) }), h('td', { 'class': 'num', text: money(s.cost) })]);
                })),
                Object.keys(job.result || {}).length ? h('pre', { 'class': 'sx-json', text: JSON.stringify(job.result, null, 2) }) : null
            ]);
        });
    }
@endverbatim
