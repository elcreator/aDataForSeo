<?php

namespace Elcreator\aDataForSeo\Http\Controllers;

use Elcreator\aDataForSeo\Models\Key;
use Elcreator\aDataForSeo\Services\DemoData;
use Elcreator\aDataForSeo\Services\Projects;
use Elcreator\aDataForSeo\Services\WorkbenchException;
use Elcreator\aDataForSeo\Support\Keys;
use Elcreator\aDataForSeo\Support\Lang;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Project settings and DataForSEO keys. The site key and the settings need
 * `adataforseo_manage`; a manager's own key only `adataforseo_spend`. The
 * password is write-only: never echoed back, blank keeps the stored one.
 */
class SettingsController extends Controller
{
    public function project(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request) {
            $project = (new Projects())->update($this->currentProject(), $request->only(['mode', 'billing', 'domain', 'markets', 'competitors', 'monthly_cap', 'job_cap', 'approve_above', 'settings', 'schedule']), $this->actor());

            return ['project' => Projects::present($project)];
        }, 'manage');
    }

    public function key(Request $request): JsonResponse
    {
        $scope = $this->text($request, 'scope', 'site') === 'user' ? 'user' : 'site';

        return $this->respond(function () use ($request, $scope) {
            $env = $this->text($request, 'env', 'prod');
            $login = $this->text($request, 'login');
            $password = (string) (is_scalar($request->input('password')) ? $request->input('password') : '');
            $existing = $scope === 'user' ? Keys::userKey($this->actor()->userId()) : Keys::siteKey();

            // Demo data is a way in, not a way back from a real login.
            if ($env === 'demo' && $existing !== null && $existing->env !== 'demo') {
                throw WorkbenchException::refused('error_demo_after_login', [], ['scope' => $scope]);
            }

            if ($env !== 'demo') {
                if ($login === '' || !preg_match('/^\S{3,191}$/', $login)) {
                    throw WorkbenchException::fields(['login' => Lang::get('aDataForSeo::global.error_login')]);
                }

                if ($password === '' && ($existing === null || $existing->login !== $login || $existing->env === 'demo')) {
                    throw WorkbenchException::fields(['password' => Lang::get('aDataForSeo::global.error_password')]);
                }
            }

            $previous = $existing?->getAttributes();
            $key = Keys::save($scope, $this->actor()->userId(), $login, $password, $env);
            $verified = null;

            if ($this->flag($request, 'verify', true)) {
                $credentials = Keys::credentialsOf($key);

                try {
                    $verified = $credentials === null ? null : Keys::verify($credentials, $key);
                } catch (\Elcreator\aDataForSeo\Api\ApiException $e) {
                    // A login that does not work replaces nothing (a typo must not lock someone out of the demo).
                    $previous === null ? $key->delete() : $key->forceFill($previous)->save();

                    throw $e;
                }
            }

            // A working real login for the site: what the demo invented goes, everywhere.
            $forgotten = $scope === 'site' && $env !== 'demo' && DemoData::present() ? DemoData::forget() : null;

            return ['key' => Keys::describe($this->actor()->principal(), $this->currentProject()), 'verified' => $verified !== null, 'demo_forgotten' => $forgotten];
        }, $scope === 'user' ? 'spend' : 'manage');
    }

    public function verify(): JsonResponse
    {
        return $this->respond(function () {
            $key = Keys::userKey($this->actor()->userId()) ?? Keys::siteKey();
            $credentials = $key === null ? null : Keys::credentialsOf($key);

            if ($credentials === null) {
                throw WorkbenchException::invalid('error_no_key');
            }

            Keys::verify($credentials, $key);

            return ['key' => Keys::describe($this->actor()->principal(), $this->currentProject())];
        }, 'spend');
    }

    public function deleteKey(Request $request): JsonResponse
    {
        $scope = $this->text($request, 'scope', 'site') === 'user' ? 'user' : 'site';

        $provider = $this->text($request, 'provider', Keys::PROVIDER_DATAFORSEO) === Keys::PROVIDER_GATEWAY ? Keys::PROVIDER_GATEWAY : Keys::PROVIDER_DATAFORSEO;

        return $this->respond(function () use ($scope, $provider) {
            Key::query()->where('provider', $provider)->where('scope', $scope)->when($scope === 'user', fn ($q) => $q->where('user_id', $this->actor()->userId()))->delete();

            return ['key' => Keys::describe($this->actor()->principal(), $this->currentProject())];
        }, $scope === 'user' ? 'spend' : 'manage');
    }
}
