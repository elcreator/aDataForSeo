<?php

namespace Elcreator\aDataForSeo\Http\Controllers;

use Elcreator\aDataForSeo\Services\Cards;
use Elcreator\aDataForSeo\Services\Fixes;
use Elcreator\aDataForSeo\Services\Reports;
use Elcreator\aDataForSeo\Services\WorkbenchException;
use Elcreator\aDataForSeo\Support\Lang;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The cards and their buttons. Applying a fix needs what the CMS needs for
 * that change, checked by the fix itself at that moment.
 */
class CardController extends Controller
{
    public function index(): JsonResponse
    {
        return $this->respond(fn () => ['cards' => Cards::for($this->currentProject()), 'state' => Cards::state($this->currentProject())]);
    }

    public function preview(Request $request, int $id): JsonResponse
    {
        return $this->respond(function () use ($request, $id) {
            $fixes = new Fixes($this->actor());

            return $fixes->previewIssue($fixes->issue($this->currentProject(), $id), $this->input($request));
        });
    }

    public function apply(Request $request, int $id): JsonResponse
    {
        return $this->respond(function () use ($request, $id) {
            $fixes = new Fixes($this->actor());
            $changes = $fixes->applyIssue($fixes->issue($this->currentProject(), $id), $this->input($request));

            return ['changes' => array_map([Fixes::class, 'presentChange'], $changes)];
        });
    }

    public function ignore(Request $request, int $id): JsonResponse
    {
        return $this->respond(function () use ($request, $id) {
            $fixes = new Fixes($this->actor());
            $issue = $fixes->ignore($fixes->issue($this->currentProject(), $id), $this->flag($request, 'ignore', true));

            return ['status' => $issue->status];
        });
    }

    public function previewTarget(Request $request, int $id): JsonResponse
    {
        return $this->respond(function () use ($request, $id) {
            $fixes = new Fixes($this->actor());

            return $fixes->previewTarget($fixes->target($this->currentProject(), $id), $this->kind($request), $this->input($request));
        });
    }

    public function applyTarget(Request $request, int $id): JsonResponse
    {
        return $this->respond(function () use ($request, $id) {
            $fixes = new Fixes($this->actor());
            $target = $fixes->target($this->currentProject(), $id);
            $changes = $fixes->applyTarget($target, $this->kind($request), $this->input($request));

            return ['changes' => array_map([Fixes::class, 'presentChange'], $changes), 'target' => Reports::presentTarget($target->refresh())];
        });
    }

    /** A professional maps a target to a document by hand (sites without a clean URL scheme). */
    public function assign(Request $request, int $id): JsonResponse
    {
        return $this->respond(function () use ($request, $id) {
            $target = (new Fixes($this->actor()))->target($this->currentProject(), $id);
            $document = (int) $request->input('document_id', 0);

            if ($document > 0 && !DB::table('site_content')->where('id', $document)->where('deleted', 0)->exists()) {
                throw WorkbenchException::fields(['document_id' => Lang::get('aDataForSeo::global.error_parent')]);
            }

            $target->forceFill(['document_id' => $document > 0 ? $document : null, 'document_manual' => $document > 0, 'status' => $document > 0 ? 'exists' : $target->status])->save();

            return ['target' => Reports::presentTarget($target)];
        }, 'manage');
    }

    public function undo(int $id): JsonResponse
    {
        return $this->respond(fn () => ['change' => Fixes::presentChange((new Fixes($this->actor()))->undo($this->currentProject(), $id))]);
    }

    private function kind(Request $request): string
    {
        return $this->text($request, 'kind', 'draft') === 'seo_fields' ? 'seo_fields' : 'draft';
    }

    private function input(Request $request): array
    {
        $fields = array_filter($this->listOf($request, 'fields'), 'is_scalar');

        return array_filter([
            'fields' => $fields,
            'new_url' => $this->text($request, 'new_url'),
            'parent' => $request->input('parent') !== null ? (int) $request->input('parent') : null,
            'pagetitle' => $this->text($request, 'pagetitle'),
        ], static fn ($v) => $v !== null && $v !== '' && $v !== []);
    }
}
