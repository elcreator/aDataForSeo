<?php

namespace Elcreator\aDataForSeo\Http\Controllers;

use Elcreator\aDataForSeo\Services\Exports;
use Elcreator\aDataForSeo\Services\Reports;
use Elcreator\aDataForSeo\Services\WorkbenchException;
use Elcreator\aDataForSeo\Support\Lang;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The data behind the cards: read-only, `adataforseo` is enough. */
class DataController extends Controller
{
    public function matrix(Request $request): JsonResponse
    {
        return $this->respond(fn () => Reports::matrix($this->currentProject(), $request->only(['country', 'lang', 'tier', 'status', 'deal', 'type'])));
    }

    public function keywords(Request $request): JsonResponse
    {
        return $this->respond(fn () => Reports::keywords($this->currentProject(), $request->only(['target', 'market', 'lang', 'q', 'page'])));
    }

    public function rankings(): JsonResponse
    {
        return $this->respond(fn () => Reports::rankings($this->currentProject()));
    }

    public function issues(Request $request): JsonResponse
    {
        return $this->respond(fn () => Reports::issues($this->currentProject(), $request->only(['status', 'source', 'code', 'severity'])));
    }

    public function ledger(): JsonResponse
    {
        return $this->respond(fn () => Reports::ledger($this->currentProject()));
    }

    public function changes(): JsonResponse
    {
        return $this->respond(fn () => Reports::changes($this->currentProject()));
    }

    public function document(int $id): JsonResponse
    {
        return $this->respond(fn () => Reports::document($this->currentProject(), $id));
    }

    public function export(string $kind, string $format): Response
    {
        if (!$this->actor()->mayUse()) {
            return $this->fail('forbidden', Lang::get('aDataForSeo::global.error_forbidden'), 403);
        }

        if (!in_array($kind, Exports::KINDS, true)) {
            return $this->fail('not_found', Lang::get('aDataForSeo::global.error_export'), 404);
        }

        try {
            $body = $format === 'xlsx' ? Exports::xlsx($this->currentProject(), $kind) : Exports::csv($this->currentProject(), $kind);
        } catch (WorkbenchException|\RuntimeException $e) {
            return $this->fail('export', $e->getMessage(), 422);
        }

        $type = $format === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv; charset=UTF-8';

        return new Response($body, 200, [
            'Content-Type' => $type,
            'Content-Disposition' => 'attachment; filename="adataforseo-' . $kind . '-' . date('Y-m-d') . '.' . $format . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
