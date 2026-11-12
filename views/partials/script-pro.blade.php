@verbatim
    // ------------------------------------------------------------------
    // Professional tabs: matrix, keywords, rankings, issues, ledger, explorer
    // ------------------------------------------------------------------

    function exportLinks(kind) {
        return h('span', { 'class': 'sx-toolbar', style: 'margin:0' }, ['csv', 'xlsx'].map(function (f) {
            return h('a', { 'class': 'sx-btn sx-btn-small', href: BASE + '/export/' + kind + '.' + f }, [icon('download'), ' ' + f.toUpperCase()]);
        }));
    }

    function loading(main, path, render) {
        // add() returns the parent: keep a reference to the placeholder itself, or clearing it would empty the whole tab.
        var holder = h('div', null, h('p', { 'class': 'sx-muted', text: t('ui_loading') }));
        add(main, holder);
        api('GET', path).then(function (r) {
            clear(holder);
            if (!r || r.ok === false) { notice(holder, 'error', errMsg(r)); return; }
            render(holder, r);
        });
        return holder;
    }

    R.matrix = function (main) {
        var f = S.data.matrixFilter || (S.data.matrixFilter = { tier: '', status: '', lang: '' });
        var tier = select([['', t('ui_all_tiers')], ['P1', 'P1'], ['P2', 'P2'], ['P3', 'P3']], f.tier);
        var status = select([['', t('ui_all_statuses')], ['exists', t('target_status_exists')], ['not_indexed', t('target_status_not_indexed')], ['missing', t('target_status_missing')], ['needs_locale', t('target_status_needs_locale')]], f.status);
        [tier, status].forEach(function (s) { s.addEventListener('change', function () { f.tier = tier.value; f.status = status.value; go('matrix'); }); });
        add(main, h('div', { 'class': 'sx-toolbar' }, [tier, status, h('span', { 'class': 'sx-grow' }), exportLinks('matrix')]));
        var q = '?tier=' + encodeURIComponent(f.tier) + '&status=' + encodeURIComponent(f.status);
        loading(main, '/matrix' + q, function (holder, r) {
            if (!r.targets.length) { add(holder, empty('th', t('ui_matrix_empty'))); return; }
            add(holder, h('p', { 'class': 'sx-muted' }, t('ui_matrix_totals', { targets: num(r.totals.targets), real: num(r.totals.demand_real), raw: num(r.totals.demand_raw), ratio: r.totals.ratio === null ? '—' : r.totals.ratio })));
            add(holder, h('div', { 'class': 'sx-panel' }, table([t('ui_tier'), t('ui_page'), t('ui_keyword'), { label: t('ui_demand'), num: true }, { label: t('ui_raw'), num: true }, { label: 'KD', num: true }, { label: t('ui_score'), num: true }, t('ui_status'), ''], r.targets.map(function (tg) {
                var actions = h('div', { 'class': 'sx-toolbar', style: 'margin:0' });
                if (!tg.document_id) { add(actions, btn(t('ui_create_draft'), { small: true, onclick: function () { draftDialog(tg); } })); }
                else { add(actions, btn(t('ui_apply_title'), { small: true, onclick: function () { targetSeoDialog(tg); } })); }
                add(actions, btn(t('ui_keywords'), { small: true, onclick: function () { S.data.kwFilter = { target: tg.id, q: '', page: 1 }; go('keywords'); } }));
                if (S.boot.flags.may_manage) { add(actions, btn(t('ui_assign'), { small: true, onclick: function () { assignDialog(tg); } })); }
                if (tg.serp) { add(actions, btn('SERP', { small: true, onclick: function () { serpDialog(tg); } })); }
                return h('tr', null, [
                    h('td', null, tg.tier ? badge(tg.tier, tg.tier) : h('span', { 'class': 'sx-muted', text: tg.measured ? '' : t('ui_not_measured') })),
                    h('td', null, [h('b', { text: [tg.geo || t('ui_whole_country'), tg.deal, tg.type].filter(Boolean).join(' · ') }), h('span', { 'class': 'sx-muted', text: ' ' + tg.country + '/' + tg.lang }),
                        h('div', { 'class': 'sx-small sx-muted', text: tg.url || tg.proposed_url || '' })]),
                    h('td', { text: tg.primary_keyword || '—' }),
                    h('td', { 'class': 'num' }, [num(tg.demand_real), tg.demand_ambiguous ? h('div', { 'class': 'sx-small sx-muted', text: '+' + num(tg.demand_ambiguous) + ' ' + t('ui_ambiguous') }) : null, tg.demand_origin ? h('div', { 'class': 'sx-small sx-muted', text: '+' + num(tg.demand_origin) + ' ' + t('ui_origin') }) : null]),
                    h('td', { 'class': 'num' }, [num(tg.demand_raw), tg.ratio && tg.ratio > 1.05 ? h('div', { 'class': 'sx-small sx-muted', text: '×' + tg.ratio }) : null]),
                    h('td', { 'class': 'num', text: tg.kd_serp === null ? '—' : num(Math.round(tg.kd_serp)) }),
                    h('td', { 'class': 'num', text: tg.score === null ? '—' : num(Math.round(tg.score)) }),
                    h('td', null, badge(t('target_status_' + tg.status), tg.status)),
                    h('td', null, actions)
                ]);
            }))));
        });
    };

    var AGGREGATES = [['results', 'ui_agg_results'], ['sites', 'ui_agg_sites'], ['ugc', 'ui_agg_ugc'], ['unknown', 'ui_agg_unknown'], ['strongSites', 'ui_agg_strong'], ['strongest', 'ui_agg_strongest'], ['median', 'ui_agg_median'], ['otherLanguage', 'ui_agg_other_language']];

    /**
     * The top 10 behind a difficulty, as far as it is known. Scored through ai.artur.work there
     * is no list of sites (it explains a score with counts), so the counts are shown instead.
     */
    function serpDialog(tg) {
        var s = tg.serp || {};
        var results = s.results || [];
        var counts = s.aggregates && typeof s.aggregates === 'object' ? s.aggregates : null;
        var body = [
            s.optimistic ? h('div', { 'class': 'sx-alert sx-alert-warn', text: t('ui_kd_optimistic') }) : null,
            h('p', { 'class': 'sx-muted', text: t('ui_native_share', { n: tg.native_share === null ? '—' : Math.round(tg.native_share * 100) + '%' }) })
        ];
        if (results.length) {
            body.push(table(['#', t('ui_host'), t('ui_title_col'), { label: t('ui_strength'), num: true }], results.map(function (row) {
                return h('tr', null, [h('td', { text: row.position }), h('td', null, [row.host || '—', row.ugc ? badge('UGC') : null]), h('td', { text: row.title }), h('td', { 'class': 'num', text: row.strength })]);
            })));
        } else if (counts) {
            body.push(h('h3', { text: t('ui_serp_aggregates') }));
            body.push(h('p', { 'class': 'sx-muted sx-small', text: t('ui_serp_aggregates_help') }));
            body.push(table([t('ui_field'), { label: t('ui_value'), num: true }], AGGREGATES.filter(function (a) { return counts[a[0]] !== undefined && counts[a[0]] !== null; }).map(function (a) {
                return h('tr', null, [h('td', { text: t(a[1]) }), h('td', { 'class': 'num', text: num(counts[a[0]]) })]);
            })));
        } else {
            body.push(h('p', { 'class': 'sx-muted', text: t('ui_not_measured') }));
        }
        modal('SERP: ' + (s.keyword || tg.primary_keyword || ''), body);
    }

    function targetSeoDialog(tg) {
        api('POST', '/targets/' + tg.id + '/preview', { kind: 'seo_fields' }).then(function (r) {
            var inputs = {};
            var body = [];
            if (!r || r.ok === false) { body.push(h('div', { 'class': 'sx-alert sx-alert-error', text: errMsg(r) })); }
            else {
                if (!S.boot.flags.sseo) { body.push(sseoMissing()); }
                (r.changes || []).forEach(function (ch) {
                    inputs[ch.field] = input('text', ch.after);
                    body.push(h('div', { 'class': 'sx-diff' }, [h('span', { 'class': 'sx-muted', text: t('field_' + ch.field) }), h('div', null, [ch.before ? h('div', { 'class': 'sx-before', text: ch.before }) : null, inputs[ch.field]])]));
                });
                if (!(r.changes || []).length) { body.push(h('p', { 'class': 'sx-muted', text: t('ui_nothing_to_change') })); }
            }
            var m = modal(t('ui_apply_title'), body, S.boot.flags.sseo && r && r.ok !== false && r.changes.length ? [btn(t('ui_apply'), { primary: true, icon: 'check', onclick: function (b) {
                b.disabled = true;
                var fields = {};
                Object.keys(inputs).forEach(function (k) { fields[k] = inputs[k].value; });
                api('POST', '/targets/' + tg.id + '/apply', { kind: 'seo_fields', fields: fields }).then(function (res) {
                    if (!res || res.ok === false) { add(m.body, h('div', { 'class': 'sx-alert sx-alert-error', text: errMsg(res) })); b.disabled = false; return; }
                    m.close();
                    live(t('ui_applied'));
                });
            } })] : []);
        });
    }

    function assignDialog(tg) {
        var doc = input('number', tg.document_id || '', { min: '0' });
        var m = modal(t('ui_assign'), [h('p', { text: t('ui_assign_help') }), field(t('field_document_id'), doc)], [btn(t('ui_save'), { primary: true, onclick: function () {
            api('POST', '/targets/' + tg.id + '/document', { document_id: Number(doc.value) || 0 }).then(function (r) {
                if (!r || r.ok === false) { add(m.body, h('div', { 'class': 'sx-alert sx-alert-error', text: errMsg(r) })); return; }
                m.close();
                go('matrix');
            });
        } })]);
    }

    R.keywords = function (main) {
        var f = S.data.kwFilter || (S.data.kwFilter = { target: '', q: '', page: 1 });
        var q = input('text', f.q, { placeholder: t('ui_search') });
        q.addEventListener('change', function () { f.q = q.value; f.page = 1; go('keywords'); });
        add(main, h('div', { 'class': 'sx-toolbar' }, [q, f.target ? btn(t('ui_clear_filter'), { small: true, onclick: function () { f.target = ''; go('keywords'); } }) : null, h('span', { 'class': 'sx-grow' }), exportLinks('keywords')]));
        loading(main, '/keywords?target=' + encodeURIComponent(f.target) + '&q=' + encodeURIComponent(f.q) + '&page=' + f.page, function (holder, r) {
            if (!r.keywords.length) { add(holder, empty('key', t('ui_keywords_empty'))); return; }
            add(holder, h('div', { 'class': 'sx-panel' }, table([t('ui_keyword'), t('ui_lang'), { label: t('ui_volume'), num: true }, t('ui_counts'), t('ui_class'), t('ui_source')], r.keywords.map(function (k) {
                return h('tr', null, [
                    h('td', null, [k.keyword, k.brand ? badge(t('ui_brand')) : null, k.origin ? badge(t('ui_origin')) : null]),
                    h('td', { text: k.market + '/' + k.lang }),
                    h('td', { 'class': 'num', text: num(k.volume) }),
                    h('td', null, k.counted ? badge(t('ui_counted'), 'done') : (k.shared ? badge(t('ui_shared'), 'medium') : (k.cluster_id ? h('span', { 'class': 'sx-small sx-muted', text: t('ui_variant_of', { id: k.cluster_id }) }) : ''))),
                    h('td', { 'class': 'sx-small', text: [k.geo, k.deal, k.type].filter(Boolean).join(' · ') }),
                    h('td', { 'class': 'sx-small', text: k.source })
                ]);
            }))));
            var pages = Math.ceil(r.total / r.per_page);
            if (pages > 1) {
                add(holder, h('div', { 'class': 'sx-toolbar' }, [
                    btn('‹', { small: true, disabled: f.page <= 1, onclick: function () { f.page--; go('keywords'); } }),
                    h('span', { text: f.page + ' / ' + pages }),
                    btn('›', { small: true, disabled: f.page >= pages, onclick: function () { f.page++; go('keywords'); } })
                ]));
            }
        });
    };

    R.rankings = function (main) {
        add(main, h('div', { 'class': 'sx-toolbar' }, [h('span', { 'class': 'sx-grow' }), exportLinks('rankings')]));
        loading(main, '/rankings', function (holder, r) {
            if (!r.rows.length) { add(holder, empty('line-chart', t('ui_rankings_empty'))); return; }
            add(holder, h('div', { 'class': 'sx-panel' }, table([t('ui_keyword')].concat(r.dates.map(function (d) { return { label: d, num: true }; })).concat([{ label: t('ui_change'), num: true }]), r.rows.map(function (row) {
                return h('tr', null, [h('td', null, [row.keyword, (row.features || []).indexOf('ai_overview') >= 0 ? badge('AI') : null])].concat(r.dates.map(function (d) {
                    var p = row.positions[d];
                    return h('td', { 'class': 'num', text: p === null || p === undefined ? '—' : p });
                })).concat([h('td', { 'class': 'num ' + (row.delta > 0 ? 'sx-up' : (row.delta < 0 ? 'sx-down' : '')), text: row.delta === null ? '' : (row.delta > 0 ? '▲' + row.delta : (row.delta < 0 ? '▼' + Math.abs(row.delta) : '=')) })]));
            }))));
        });
    };

    R.issues = function (main) {
        var f = S.data.issueFilter || (S.data.issueFilter = { status: 'open' });
        var status = select([['open', t('status_open')], ['applied', t('status_applied')], ['ignored', t('status_ignored')], ['resolved', t('status_resolved')], ['all', t('ui_all_statuses')]], f.status);
        status.addEventListener('change', function () { f.status = status.value; go('issues'); });
        add(main, h('div', { 'class': 'sx-toolbar' }, [status, h('span', { 'class': 'sx-grow' }), exportLinks('issues')]));
        loading(main, '/issues?status=' + encodeURIComponent(f.status), function (holder, r) {
            if (!r.issues.length) { add(holder, empty('check', t('ui_issues_empty'))); return; }
            add(holder, h('div', { 'class': 'sx-panel' }, table([t('ui_severity'), t('ui_finding'), 'URL', t('ui_status'), ''], r.issues.map(function (i) {
                return h('tr', null, [h('td', null, badge(t('sev_' + i.severity), i.severity)), h('td', { text: tOr('code_' + i.code, i.code) }), h('td', { 'class': 'sx-small', text: i.url }),
                    h('td', null, badge(t('status_' + i.status), i.status)),
                    h('td', null, i.status === 'ignored' ? btn(t('ui_restore'), { small: true, onclick: function () { api('POST', '/issues/' + i.id + '/ignore', { ignore: false }).then(function () { go('issues'); }); } }) : null)]);
            }))));
        });
        loading(main, '/changes', function (holder, r) {
            if (!r.changes.length) { return; }
            add(holder, h('div', { 'class': 'sx-panel' }, [h('h2', { text: t('ui_changes') }), table([t('ui_when'), t('ui_target'), t('ui_field'), t('ui_before'), t('ui_after'), ''], r.changes.map(function (c) {
                return h('tr', null, [h('td', { 'class': 'sx-small', text: when(c.applied_at) }), h('td', { text: c.target + (c.resource_id ? ' #' + c.resource_id : '') }), h('td', { text: c.field }),
                    h('td', { 'class': 'sx-small', text: c.before || '' }), h('td', { 'class': 'sx-small', text: c.after || '' }),
                    h('td', null, c.undone_at ? h('span', { 'class': 'sx-muted', text: t('ui_undone') }) : btn(t('ui_undo'), { small: true, icon: 'undo', onclick: function () {
                        api('POST', '/changes/' + c.id + '/undo', {}).then(function (res) { if (!res || res.ok === false) { notice($('sx-page-error'), 'error', errMsg(res)); } go('issues'); });
                    } }))]);
            }))]));
        });
    };

    R.ledger = function (main) {
        loading(main, '/ledger', function (holder, r) {
            add(holder, h('div', { 'class': 'sx-panel' }, [h('h2', { text: t('ui_spend_by_month') }), table([t('ui_month'), { label: t('ui_spent'), num: true }], r.months.map(function (m) { return h('tr', null, [h('td', { text: m.month }), h('td', { 'class': 'num', text: money(m.spent, null, r.currency) })]); }))]));
            add(holder, h('div', { 'class': 'sx-panel' }, [h('h2', { text: t('ui_calls') }), r.calls.length ? table([t('ui_when'), t('ui_endpoint'), { label: t('ui_estimate'), num: true }, { label: t('ui_cost'), num: true }, t('ui_cached'), { label: t('ui_items'), num: true }], r.calls.map(function (c) {
                return h('tr', null, [h('td', { 'class': 'sx-small', text: when(c.fetched_at) }), h('td', null, [h('code', { text: c.endpoint }), c.env !== 'prod' ? badge(c.env) : null]),
                    h('td', { 'class': 'num', text: c.estimate === null ? '—' : money(c.estimate, null, c.currency) }), h('td', { 'class': 'num', text: money(c.cost, null, c.currency) }),
                    h('td', null, c.cached ? badge(t('ui_cached'), 'done') : ''), h('td', { 'class': 'num', text: num(c.items) })]);
            })) : h('p', { 'class': 'sx-muted', text: t('ui_ledger_empty') })]));
        });
    };

    R.explorer = function (main) {
        // Raw requests go to the site's own DataForSEO account; through ai.artur.work there is nothing to send them to.
        if (isGateway()) { add(main, h('div', { 'class': 'sx-alert sx-alert-info', text: t('ui_gateway_explorer') })); return; }
        var x = S.data.explorer || (S.data.explorer = { endpoint: 'dataforseo_labs/google/keyword_suggestions/live', payload: '[{"keyword": "byty atlantida", "location_code": 2203, "language_code": "cs", "limit": 20}]' });
        var endpoint = input('text', x.endpoint);
        var payload = h('textarea', { rows: 8, text: x.payload });
        var out = h('div');
        var priceLine = h('p', { 'class': 'sx-muted' });
        var read = function () { x.endpoint = endpoint.value.trim(); x.payload = payload.value; return { endpoint: x.endpoint, payload: x.payload }; };
        var run = function (confirm) {
            var body = read();
            body.confirm = !!confirm;
            api('POST', '/explorer/run', body).then(function (r) {
                clear(out);
                if (!r || r.ok === false) { notice(out, 'error', errMsg(r)); return; }
                if (r.needs_confirmation) {
                    add(out, h('div', { 'class': 'sx-alert sx-alert-warn' }, [t('ui_explorer_confirm', { price: estimateText(r.estimate) }), h('div', null, btn(t('ui_run_anyway'), { primary: true, onclick: function () { run(true); } }))]));
                    return;
                }
                add(out, h('p', null, [t('ui_estimate') + ': ' + estimateText(r.estimate) + ' · ' + t('ui_cost') + ': ', h('b', { text: money(r.cost) }), r.cached ? ' (' + t('ui_cached') + ')' : '']));
                add(out, h('pre', { 'class': 'sx-json', text: JSON.stringify(r.response, null, 2) }));
            });
        };
        add(main, h('div', { 'class': 'sx-panel' }, [
            h('h2', { text: t('ui_tab_explorer') }),
            h('p', { 'class': 'sx-muted', text: t('ui_explorer_help') }),
            field(t('ui_endpoint'), endpoint),
            field(t('ui_payload'), payload, t('ui_payload_help')),
            priceLine,
            h('div', { 'class': 'sx-toolbar' }, [
                btn(t('ui_estimate'), { icon: 'calculator', onclick: function () {
                    api('POST', '/explorer/estimate', read()).then(function (r) { priceLine.textContent = !r || r.ok === false ? errMsg(r) : t('ui_estimate') + ': ' + estimateText(r.estimate) + (r.queued ? ' · ' + t('ui_explorer_queued') : ''); });
                } }),
                btn(t('ui_run'), { primary: true, icon: 'play', disabled: !S.boot.flags.may_spend, onclick: function () { run(false); } })
            ]),
            out
        ]));
    };
@endverbatim
