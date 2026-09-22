<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Document\StoreDocumentTemplateRequest;
use App\Http\Requests\Document\UpdateDocumentTemplateRequest;
use App\Http\Resources\DocumentTemplateResource;
use App\Models\DocumentTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/** `document-templates` — contrato docs/gap-simplesvet/contratos/15-contrato-api.md. */
class DocumentTemplateController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $templates = DocumentTemplate::query()
            ->visibleTo($request->user()->activeOrganizationId())
            ->when($request->query('kind'), fn ($query, $kind) => $query->where('kind', $kind))
            ->orderBy('name')
            ->get();

        return DocumentTemplateResource::collection($templates);
    }

    public function store(StoreDocumentTemplateRequest $request): JsonResponse
    {
        $organizationId = $request->user()->activeOrganizationId();
        abort_if($organizationId === null, 422, 'É preciso ter uma organização ativa para cadastrar um modelo de documento.');

        $template = DocumentTemplate::create([
            ...$request->validated(),
            'organization_id' => $organizationId,
        ]);

        return response()->json(['data' => new DocumentTemplateResource($template)], 201);
    }

    public function show(Request $request, DocumentTemplate $documentTemplate): DocumentTemplateResource
    {
        Gate::forUser($request->user())->authorize('view', $documentTemplate);

        return new DocumentTemplateResource($documentTemplate);
    }

    public function update(UpdateDocumentTemplateRequest $request, DocumentTemplate $documentTemplate): JsonResponse
    {
        Gate::forUser($request->user())->authorize('update', $documentTemplate);

        $documentTemplate->update($request->validated());

        return response()->json(['data' => new DocumentTemplateResource($documentTemplate->fresh())]);
    }

    public function destroy(Request $request, DocumentTemplate $documentTemplate): JsonResponse
    {
        Gate::forUser($request->user())->authorize('delete', $documentTemplate);

        $documentTemplate->delete();

        return response()->json(['message' => 'Modelo de documento removido com sucesso.']);
    }
}
