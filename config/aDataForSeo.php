<?php

/**
 * aDataForSeo settings, merged under `cms.settings.aDataForSeo`.
 *
 * Override in `core/custom/config/cms/settings/aDataForSeo.php`. Secrets never
 * live here: the DataForSEO login comes from the module's key store
 * (encrypted) or the DATAFORSEO_LOGIN / DATAFORSEO_PASSWORD environment.
 */
return [
    'enable' => true,

    'api' => [
        // prod | sandbox (free, dummy data in real shapes) | demo (offline, no account needed)
        'env' => 'prod',
        'timeout' => 120,
        'connect_timeout' => 10,
        // How long the price table from appendix/user_data is trusted; stale is used when a refresh fails.
        'pricing_ttl' => 86400,
        // Seconds a stored answer is reused, by endpoint family (first matching prefix wins).
        'cache_ttl' => [
            'dataforseo_labs' => 30 * 86400,
            'keywords_data' => 30 * 86400,
            'serp' => 7 * 86400,
            'on_page' => 3 * 86400,
            'appendix' => 86400,
            'default' => 86400,
        ],
        // Task-level codes worth another attempt (rate limits, internal errors, "try again later").
        'retryable_codes' => [40202, 40209, 40210, 50000, 50301, 50303],
        // The "Try DataForSeo for free" link for someone without an account: an affiliate link, shown marked "(Ad)" with a disclosure.
        'signup_url' => 'https://try.dataforseo.com/su3o1t15dxhx',
    ],

    // Pay from an ai.artur.work balance instead of a DataForSEO account: analytics only
    // (index coverage, audit, difficulty), priced in euros. The base URL is the only place
    // the gateway's address lives.
    'gateway' => [
        'url' => 'https://ai.artur.work',
        'timeout' => 60,
        'connect_timeout' => 10,
        // Back-off between checks of a queued gateway task; the last value repeats.
        'poll_seconds' => [15, 30, 60, 120],
        'connect' => [
            // The app slug "Connect a site" labels the key with; the gateway shows an unknown slug as sent.
            'app' => 'adataforseo',
            // Seconds a started Connect may take before its state is refused.
            'pending_ttl' => 900,
        ],
    ],

    'queue' => [
        // auto (sTask when installed, else the CMS task queue) | system | stask
        'driver' => 'auto',
        // GET /adataforseo/ping/{token}: DataForSEO wakes a waiting job instead of the next tick.
        'pingback' => false,
    ],

    'limits' => [
        'slice_seconds' => 45,
        'parallelism' => 2,
        // Back-off between `tasks_ready` checks; the last value repeats.
        'poll_seconds' => [60, 120, 300],
        'stale_after' => 900,
        // site: result pages of 100 for index coverage.
        'coverage_pages' => 3,
        'history_days' => 180,
        'max_attempts' => 5,
        // OnPage crawl size when the job does not say.
        'crawl_pages' => 200,
        // Targets checked by one difficulty job.
        'serp_checks' => 60,
    ],

    'budget' => [
        // In the project's currency: US dollars with a DataForSEO account, euros when paying from the ai.artur.work balance.
        'monthly_cap' => 5.00,
        'job_cap' => 2.00,
        'approve_above' => 0.50,
        // Ledger vs account balance drift that is logged by the daily reconciliation.
        'drift_alert' => 0.05,
    ],

    'research' => [
        'vertical' => 'real_estate',
        'serp_check_min_demand' => 50,
        'suggestions_limit' => 200,
        'competitor_keywords_limit' => 200,
        'weights' => [
            'w_amb' => 0.5,
            'w_origin' => 0.5,
            'bonus' => 1.25,
            't' => 0.5,
            'locale_factor' => 0.8,
        ],
        // Difficulty assumed for a target whose SERP was not checked: local language vs other languages.
        'default_kd' => [
            'local' => 40,
            'foreign' => 20,
        ],
    ],

    'tracking' => [
        'depth' => 20,
        'secondary' => 2,
        // Days between scheduled runs.
        'every_days' => 7,
    ],

    'fixes' => [
        // What a manager needs, besides adataforseo, to add redirects to sSeo's table.
        'redirect_permission' => 'exec_module',
    ],

    'mcp' => [
        'enabled' => true,
        'server' => 'content',
    ],
];
