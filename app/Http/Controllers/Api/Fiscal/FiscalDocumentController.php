<?php

namespace App\Http\Controllers\Api\Fiscal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Fiscal\StoreFiscalCancellationRequest;
use App\Enums\FiscalDocumentKind;
use App\Enums\FiscalDocumentStatus;
use App\Http\Resources\Fiscal\FiscalDocumentResource;
use App\Models\FiscalDocument;
use App\Models\Sale;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Fiscal\FiscalDocumentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Emissão fiscal — contrato docs/gap-simplesvet/05-emissao-fiscal-nfe-nfce-nfse-spec.md. Só
 * `OWNER` (`FiscalDocumentPolicy`): gera obrigação/custo fiscal, não é ação de balcão.
 */
class FiscalDocumentController extends Controller
{
    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly FiscalDocumentService $fiscalDocuments,
    ) {}

    public function store(Request $request, int $saleId): JsonResponse
    {
        Gate::forUser($request->user())->authorize('create', FiscalDocument::class);

        $sale = $this->scope->scopeQuery(Sale::query(), $request->user())->with('items')->findOrFail($saleId);
        $documents = $this->fiscalDocuments->issueFromSale($sale, $request->user());

        return response()->json([
            'data' => FiscalDocumentResource::collection($documents),
            'message' => 'Documento fiscal emitido.',
        ], 201);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::forUser($request->user())->authorize('viewAny', FiscalDocument::class);

        $request->validate([
            'status' => ['nullable', Rule::enum(FiscalDocumentStatus::class)],
            'kind' => ['nullable', Rule::enum(FiscalDocumentKind::class)],
        ]);

        $documents = $this->scopedQuery($request->user())
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('kind'), fn (Builder $q) => $q->where('kind', $request->string('kind')->toString()))
            ->orderByDesc('id')
            ->get();

        return FiscalDocumentResource::collection($documents);
    }

    public function show(Request $request, int $id): FiscalDocumentResource
    {
        $document = $this->findForUser($request->user(), $id, 'view');

        return new FiscalDocumentResource($document);
    }

    public function cancel(StoreFiscalCancellationRequest $request, int $id): FiscalDocumentResource
    {
        $document = $this->findForUser($request->user(), $id, 'cancel');
        $document = $this->fiscalDocuments->cancel($document, $request->string('reason')->toString());

        return new FiscalDocumentResource($document);
    }

    public function pending(Request $request): AnonymousResourceCollection
    {
        Gate::forUser($request->user())->authorize('viewAny', FiscalDocument::class);

        $documents = $this->scopedQuery($request->user())->pending()->orderBy('id')->get();

        return FiscalDocumentResource::collection($documents);
    }

    private function findForUser(User $user, int $id, string $ability): FiscalDocument
    {
        $document = $this->scopedQuery($user)->findOrFail($id);
        Gate::forUser($user)->authorize($ability, $document);

        return $document;
    }

    /**
     * @return Builder<FiscalDocument>
     */
    private function scopedQuery(User $user): Builder
    {
        return $this->scope->scopeQuery(FiscalDocument::query(), $user);
    }
}
