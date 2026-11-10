<?php

namespace Elcreator\aDataForSeo\Http\Controllers;

use Elcreator\aDataForSeo\Support\Config;
use Elcreator\aDataForSeo\Support\Manager;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The module page: its own document inside the manager frame, handed only
 * what the browser cannot derive (route prefix, CSRF token, icons and, on
 * 3.6+, evo-ui's theme). Everything else comes from `/bootstrap`.
 */
class PageController extends Controller
{
    public function index(Request $request): View
    {
        if (!$this->actor()->mayUse()) {
            return view('aDataForSeo::denied');
        }

        return view('aDataForSeo::page', self::viewData(
            rtrim($request->getBaseUrl() . $request->getPathInfo(), '/'),
            $this->csrfToken()
        ));
    }

    /** @return array<string, mixed> */
    public static function viewData(string $baseUrl, string $csrfToken): array
    {
        return [
            'baseUrl' => $baseUrl,
            'csrfToken' => $csrfToken,
            'signupUrl' => (string) Config::get('api.signup_url', ''),
            'sseoUrl' => \Elcreator\aDataForSeo\Integrations\SSeo::HOMEPAGE,
            // 3.6+: evo-ui's shell and theme; 3.5: the standalone page.
            'evoTheme' => Manager::evoTheme(),
            // Tabler SVGs when the set is installed (3.6+), else Font Awesome if this manager ships it.
            'icons' => Manager::svgIcons(),
            'iconsUrl' => Manager::fontAwesomeUrl(),
        ];
    }
}
