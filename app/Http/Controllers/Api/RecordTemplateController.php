<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RecordTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Modelos de prontuário, só leitura (doc 14, mínimo do PLANO 3.8): os globais do 2pets + os da
 * organização ativa. Permissão de rota: a de escrever prontuário (`medical-records.create`).
 */
class RecordTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $templates = RecordTemplate::visibleTo($request->user()->activeOrganizationId())
            ->where('active', true)->orderByRaw('organization_id IS NULL')->orderBy('name')->get();

        return response()->json(['data' => $templates->map(fn (RecordTemplate $t): array => $this->present($t, withBody: false))->values()]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $template = RecordTemplate::visibleTo($request->user()->activeOrganizationId())->findOrFail($id);

        return response()->json(['data' => $this->present($template, withBody: true)]);
    }

    /** @return array<string, mixed> */
    private function present(RecordTemplate $template, bool $withBody): array
    {
        return [
            'id' => $template->id,
            'name' => $template->name,
            'description' => $template->description,
            'is_global' => $template->organization_id === null,
            'active' => $template->active,
        ] + ($withBody ? ['body_html' => $template->body_html] : []);
    }
}
