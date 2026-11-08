<?php

namespace App\Http\Controllers\Api\Fiscal;

use App\DataTransferObjects\Fiscal\FiscalOwner;
use App\Enums\FiscalDocumentKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fiscal\StoreFiscalNumberInvalidationRequest;
use App\Http\Resources\Fiscal\FiscalNumberInvalidationResource;
use App\Models\FiscalDocument;
use App\Models\FiscalNumberInvalidation;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Fiscal\FiscalNumberInvalidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Inutilização de numeração e lacunas — spec docs/gap-petflow/specs/fase4-fiscal-sem-envio.md
 * §3.7. Permissão `fiscal-numbers.invalidate` (rota) + régua "só OWNER" da
 * `FiscalDocumentPolicy::create` (gera obrigação junto à SEFAZ).
 */
class FiscalNumberInvalidationController extends Controller
{
    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly FiscalNumberInvalidationService $invalidations,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::forUser($request->user())->authorize('create', FiscalDocument::class);

        $records = $this->scope->scopeQuery(FiscalNumberInvalidation::query(), $request->user())
            ->with('user:id,name')
            ->orderByDesc('id')
            ->get();

        return FiscalNumberInvalidationResource::collection($records);
    }

    public function store(StoreFiscalNumberInvalidationRequest $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('create', FiscalDocument::class);

        $invalidation = $this->invalidations->create($this->owner($request->user()), $request->user(), $request->validated());

        return (new FiscalNumberInvalidationResource($invalidation->load('user:id,name')))
            ->additional(['message' => 'Pedido de inutilização registrado.'])
            ->response()
            ->setStatusCode(201);
    }

    public function gaps(Request $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('create', FiscalDocument::class);
        $request->validate(['kind' => ['required', Rule::in([FiscalDocumentKind::NFE->value, FiscalDocumentKind::NFCE->value])]]);

        return response()->json(['data' => $this->invalidations->gaps($this->owner($request->user()), FiscalDocumentKind::from($request->string('kind')->toString()))]);
    }

    private function owner(User $user): FiscalOwner
    {
        $ownership = $this->scope->ownershipFor($user);

        return new FiscalOwner($ownership['organization_id'], $ownership['professional_id']);
    }
}
