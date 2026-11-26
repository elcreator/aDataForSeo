<?php

namespace Elcreator\aDataForSeo\Http\Controllers;

use Elcreator\aDataForSeo\Models\Issue;
use Elcreator\aDataForSeo\Models\Target;
use Elcreator\aDataForSeo\Services\Fixes;
use Elcreator\aDataForSeo\Services\GatewayWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Title and description variants from ai.artur.work for a finding (`issue`) or a target
 * page (`target`): the price first, then the variants. Both need `adataforseo_spend`.
 */
class WriterController extends Controller
{
    public function quote(Request $request): JsonResponse
    {
        return $this->respond(fn () => $this->writer()->quote($this->subject($request)), 'spend');
    }

    public function meta(Request $request): JsonResponse
    {
        return $this->respond(fn () => $this->writer()->meta($this->subject($request), $this->flag($request, 'confirm')), 'spend');
    }

    private function writer(): GatewayWriter
    {
        return new GatewayWriter($this->actor(), $this->currentProject());
    }

    private function subject(Request $request): Issue|Target
    {
        $fixes = new Fixes($this->actor());
        $target = (int) $request->input('target', 0);

        return $target > 0 ? $fixes->target($this->currentProject(), $target) : $fixes->issue($this->currentProject(), (int) $request->input('issue', 0));
    }
}
