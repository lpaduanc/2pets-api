<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesPetAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Document\StoreGeneratedDocumentRequest;
use App\Http\Resources\GeneratedDocumentResource;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Services\Document\DocumentIssuanceService;
use App\Services\Report\GeneratedDocumentPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/** `generated-documents` — contrato docs/gap-simplesvet/contratos/15-contrato-api.md. */
class GeneratedDocumentController extends Controller
{
    use AuthorizesPetAccess;

    public function __construct(
        private readonly DocumentIssuanceService $issuanceService,
        private readonly GeneratedDocumentPdfService $pdfService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $petId = (int) $request->query('pet_id');
        $pet = $this->resolvePetForRead($request, $petId);

        $documents = GeneratedDocument::where('pet_id', $pet->id)->latest('issued_at')->get();

        return GeneratedDocumentResource::collection($documents);
    }

    public function store(StoreGeneratedDocumentRequest $request): JsonResponse
    {
        $data = $request->validated();
        $pet = $this->resolvePetForWrite($request, (int) $data['pet_id']);
        $template = DocumentTemplate::findOrFail($data['document_template_id']);
        // IDOR cross-tenant (revisão de segurança, achado Alto): sem esta checagem, qualquer
        // profissional com acesso de escrita a um pet consegue emitir documento usando o
        // `body_html` de um `document_template` de OUTRA organização.
        Gate::forUser($request->user())->authorize('view', $template);

        $document = $this->issuanceService->issue($template, $pet, $request->user(), $data['medical_record_id'] ?? null);

        return response()->json(['data' => new GeneratedDocumentResource($document)], 201);
    }

    public function show(Request $request, GeneratedDocument $generatedDocument): GeneratedDocumentResource
    {
        $this->resolvePetForRead($request, (int) $generatedDocument->pet_id);

        return new GeneratedDocumentResource($generatedDocument);
    }

    public function pdf(Request $request, GeneratedDocument $generatedDocument): Response
    {
        $this->resolvePetForRead($request, (int) $generatedDocument->pet_id);

        return $this->pdfService->generate($generatedDocument);
    }
}
