<?php

namespace Elcreator\aDataForSeo\Http\Controllers;

use Elcreator\aDataForSeo\Api\ApiException;
use Elcreator\aDataForSeo\Gateway\Connect;
use Elcreator\aDataForSeo\Gateway\ConnectRelay;
use Elcreator\aDataForSeo\Services\DemoData;
use Elcreator\aDataForSeo\Services\Projects;
use Elcreator\aDataForSeo\Services\WorkbenchException;
use Elcreator\aDataForSeo\Support\Billing;
use Elcreator\aDataForSeo\Support\EventLog;
use Elcreator\aDataForSeo\Support\Keys;
use Elcreator\aDataForSeo\Support\Lang;
use Elcreator\aDataForSeo\Support\Manager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The ai.artur.work key: where a manager gets one without copying anything (Connect, the
 * gateway's "Connect a site": OAuth2 code + PKCE), or pastes one. The site key and the choice
 * to pay from the gateway need `adataforseo_manage`; a manager's own key only
 * `adataforseo_spend`, as with the DataForSEO key. The key is write-only: never echoed back,
 * never logged.
 *
 * The verifier and the state live in the manager's session between the two requests; the key
 * itself never passes through the browser.
 */
class GatewayController extends Controller
{
    public const SESSION_KEY = 'adataforseo_gateway_connect';

    /** Paste a key (or, from the wizard, the key that was just connected) and, with `use`, pay from it. */
    public function key(Request $request): JsonResponse
    {
        $scope = $this->text($request, 'scope', 'site') === 'user' ? 'user' : 'site';

        return $this->respond(function () use ($request, $scope) {
            $apiKey = $this->text($request, 'key');

            if (!preg_match('/^\S{8,512}$/', $apiKey)) {
                throw WorkbenchException::fields(['key' => Lang::get('aDataForSeo::global.error_gateway_key')]);
            }

            $existing = $scope === 'user' ? Keys::userKey($this->actor()->userId(), Keys::PROVIDER_GATEWAY) : Keys::siteKey(Keys::PROVIDER_GATEWAY);
            $previous = $existing?->getAttributes();
            $key = Keys::saveGateway($scope, $this->actor()->userId(), $apiKey);
            $verified = null;

            if ($this->flag($request, 'verify', true)) {
                try {
                    $verified = Keys::verifyGateway(Keys::gatewayCredentialsOf($key), $key);
                } catch (ApiException $e) {
                    // A key that does not work replaces nothing: a typo must not lock someone out.
                    $previous === null ? $key->delete() : $key->forceFill($previous)->save();

                    throw $e;
                }
            }

            return $this->saved($scope, $this->flag($request, 'use', true)) + ['verified' => $verified !== null];
        }, $scope === 'user' ? 'spend' : 'manage');
    }

    public function verify(): JsonResponse
    {
        return $this->respond(function () {
            $key = Keys::userKey($this->actor()->userId(), Keys::PROVIDER_GATEWAY) ?? Keys::siteKey(Keys::PROVIDER_GATEWAY);
            $credentials = $key === null ? null : Keys::gatewayCredentialsOf($key);

            if ($credentials === null) {
                throw WorkbenchException::invalid('error_no_key');
            }

            Keys::verifyGateway($credentials, $key);

            return ['key' => Keys::describe($this->actor()->principal(), $this->currentProject())];
        }, 'spend');
    }

    /** Send the browser to the gateway's consent page. `scope=site` connects the site-wide key. */
    public function connect(Request $request): RedirectResponse
    {
        $scope = (string) $request->query('scope', 'user') === 'site' ? 'site' : 'user';

        if (!$this->mayConnect($scope)) {
            EventLog::refusal('gateway connect', 'forbidden', ['user' => $this->actor()->userId(), 'scope' => $scope]);

            return $this->backToPage('failed', Lang::get('aDataForSeo::global.error_forbidden'));
        }

        $pkce = Connect::pkce();
        $_SESSION[self::SESSION_KEY] = ['verifier' => $pkce['verifier'], 'state' => $pkce['state'], 'scope' => $scope, 'at' => time()];

        return new RedirectResponse(Connect::authorizeUrl(Connect::siteOrigin(), $this->callbackUrl(), $pkce['state'], $pkce['challenge']));
    }

    /** Back from the gateway with `?code&state` (or `?error`): trade the code for a key and save it. */
    public function connectCallback(Request $request): RedirectResponse
    {
        $pending = $_SESSION[self::SESSION_KEY] ?? null;
        unset($_SESSION[self::SESSION_KEY]);

        $state = (string) $request->query('state', '');

        if (!is_array($pending) || $state === '' || !hash_equals((string) ($pending['state'] ?? ''), $state) || time() - (int) ($pending['at'] ?? 0) > Connect::pendingTtl()) {
            return $this->backToPage('failed', Lang::get('aDataForSeo::global.error_gateway_connect_expired'));
        }

        if ((string) $request->query('error', '') !== '') {
            return $this->backToPage('failed', Lang::get('aDataForSeo::global.error_gateway_connect_denied'));
        }

        $scope = (string) $pending['scope'] === 'site' ? 'site' : 'user';

        // The session outlives the click: the permission is checked again now.
        if (!$this->mayConnect($scope)) {
            EventLog::refusal('gateway connect callback', 'forbidden', ['user' => $this->actor()->userId(), 'scope' => $scope]);

            return $this->backToPage('failed', Lang::get('aDataForSeo::global.error_forbidden'));
        }

        try {
            $answer = $this->connector()->exchange((string) $request->query('code', ''), (string) $pending['verifier'], $this->callbackUrl());
            $key = Keys::saveGateway($scope, $this->actor()->userId(), $answer['access_token']);
            $credentials = Keys::gatewayCredentialsOf($key);

            try {
                Keys::verifyGateway($credentials, $key);
            } catch (ApiException) {
                // The key was just issued; a hiccup reading the balance must not lose it. "Verify" reads it again.
            }

            $this->saved($scope, true);
        } catch (ApiException|WorkbenchException $e) {
            return $this->backToPage('failed', $e->getMessage());
        }

        return $this->backToPage('connected', Lang::get('aDataForSeo::global.ui_gateway_connected'));
    }

    /**
     * After a key is stored: forget demo data (a real way to pay is connected), and, for a
     * manager, switch the project to pay from the gateway.
     *
     * @return array<string, mixed>
     */
    private function saved(string $scope, bool $use): array
    {
        $forgotten = $scope === 'site' && DemoData::present() ? DemoData::forget() : null;
        $project = $this->currentProject();

        if ($use && $this->actor()->mayManage() && !Billing::isGateway($project)) {
            $project = (new Projects())->update($project, ['billing' => Billing::GATEWAY], $this->actor());
        }

        return [
            'key' => Keys::describe($this->actor()->principal(), $project),
            'billing' => Billing::modeOf($project),
            'demo_forgotten' => $forgotten,
        ];
    }

    private function mayConnect(string $scope): bool
    {
        return $scope === 'site' ? $this->actor()->mayManage() : $this->actor()->maySpend();
    }

    /** The code-for-key exchange; a seam so tests can answer in the gateway's envelopes. */
    protected function connector(): Connect
    {
        return new Connect();
    }

    /** The absolute URL the gateway sends the browser back to: it must be on the site's host. */
    protected function callbackUrl(): string
    {
        return ConnectRelay::redirectUri(Connect::siteOrigin(), $this->managerCallbackUrl());
    }

    /** The manager route the relay forwards to. */
    protected function managerCallbackUrl(): string
    {
        return route('adataforseo.gateway.callback');
    }

    protected function indexUrl(): string
    {
        return route('adataforseo.index');
    }

    protected function managerUrl(): string
    {
        return defined('EVO_MANAGER_URL') ? (string) EVO_MANAGER_URL : '';
    }

    /** Through the manager's deep link: the module route on its own renders without the manager around it. */
    private function backToPage(string $flag, string $message): RedirectResponse
    {
        $page = $this->indexUrl() . '?' . http_build_query(['gateway' => $flag, 'message' => $message]) . '#account';

        return new RedirectResponse($this->managerUrl() === '' ? $page : Manager::framedUrl($page, $this->managerUrl()));
    }
}
