{{--
    The aDataForSeo manager page.

    Rendered into the manager's main frame by a routing module, so it owns the
    whole document. On Evolution 3.6+ with evo-ui it lives in evo-ui's shell:
    evo-ui's assets, theme classes and light/dark sync, with this page's
    colours mapped onto the theme. On 3.5 it is a standalone page that links
    Font Awesome when the manager still ships it. What the front end cannot
    derive (route prefix, CSRF token, strings, icons, the sign-up link) is
    handed over as one JSON island; page data comes from GET /bootstrap.

    Expected view data: $baseUrl, $csrfToken, $signupUrl, $evoTheme (?array), $icons (array), $iconsUrl (?string).
--}}
@php
    // `$translator` is only passed by tests; the CMS container supplies it otherwise.
    $translator = $translator ?? app('translator');
    $all = array_replace(
        (array) $translator->get('aDataForSeo::global', [], 'en'),
        (array) $translator->get('aDataForSeo::global')
    );
    $dictionary = array_filter(
        $all,
        static fn ($value, $key) => is_string($value)
            && preg_match('/^(ui_|card_|code_|kind_|manual_|error_|refusal_|sev_|status_|effort_|tier_|target_status_|queue_hint|what_|field_)/', (string) $key),
        ARRAY_FILTER_USE_BOTH
    );
    $evoTheme = $evoTheme ?? null;
    $lang = str_replace('_', '-', (string) $translator->getLocale());
@endphp
<!DOCTYPE html>
@if($evoTheme)
<html lang="{{ $lang }}" class="evo-ui-page {{ $evoTheme['classes'] }}" data-theme="{{ $evoTheme['theme'] }}" data-theme-mode="{{ $evoTheme['mode'] }}" style="background-color: var(--evo-ui-bg, {{ $evoTheme['background'] }})">
@else
<html lang="{{ $lang }}">
@endif
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('aDataForSeo::global.ui_title') }}</title>
    @if($evoTheme)
        <meta name="color-scheme" content="{{ $evoTheme['mode'] === 'dark' ? 'dark light' : 'light dark' }}">
        @include('evo::partials.assets')
    @elseif($iconsUrl)
        <link rel="stylesheet" href="{{ $iconsUrl }}">
    @endif
    @include('aDataForSeo::partials.styles')
</head>
@if($evoTheme)
<body class="evo-ui-page {{ $evoTheme['classes'] }}" data-theme="{{ $evoTheme['theme'] }}" data-theme-mode="{{ $evoTheme['mode'] }}" style="background-color: var(--evo-ui-bg, {{ $evoTheme['background'] }})">
<div class="evo-ui sx-evo {{ $evoTheme['classes'] }}" data-evo-ui-root data-theme="{{ $evoTheme['theme'] }}" data-theme-mode="{{ $evoTheme['mode'] }}">
@else
<body>
<div>
@endif
<div class="sx-shell" id="sx-shell">
    <header class="sx-head">
        <div>
            <h1>{{ __('aDataForSeo::global.ui_title') }} <span class="sx-domain" id="sx-domain"></span></h1>
            <p class="sx-sub">{{ __('aDataForSeo::global.ui_tagline') }}</p>
        </div>
        <div class="sx-summary" id="sx-summary" role="status"></div>
    </header>

    <div id="sx-queue"></div>
    <div id="sx-page-error"></div>

    <section id="sx-wizard" hidden></section>

    <div class="sx-tabs" role="tablist" id="sx-tabs" aria-label="{{ __('aDataForSeo::global.ui_title') }}"></div>
    <main id="sx-main"></main>
</div>

<div id="sx-modal-root"></div>
<div class="sx-sr" id="sx-live" aria-live="polite" role="status"></div>
</div>

<script>
    window.ADATAFORSEO = @js([
        'baseUrl' => $baseUrl,
        'csrf' => $csrfToken,
        'signup' => $signupUrl,
        'sseoUrl' => $sseoUrl ?? \Elcreator\aDataForSeo\Integrations\SSeo::HOMEPAGE,
        'icons' => $icons ?? [],
        'lang' => $dictionary,
    ]);
</script>
@include('aDataForSeo::partials.script')
</body>
</html>
