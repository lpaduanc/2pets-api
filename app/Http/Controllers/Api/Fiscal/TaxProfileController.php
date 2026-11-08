<?php

namespace App\Http\Controllers\Api\Fiscal;

use App\DataTransferObjects\Fiscal\FiscalOwner;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fiscal\StoreTaxProfileRequest;
use App\Http\Resources\Fiscal\TaxProfileResource;
use App\Models\FiscalDocument;
use App\Models\TaxProfile;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Fiscal\TaxProfileService;
use App\Services\Fiscal\TaxProfileTemplates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Perfis tributários — spec docs/gap-petflow/specs/fase4-fiscal-sem-envio.md §6. Leitura com
 * `products.view|fiscal-settings.manage` (o cadastro de produto mostra o perfil); escrita com
 * `fiscal-settings.manage` (rotas). Escopo de dono pelo `CommercialScopeResolver`.
 */
class TaxProfileController extends Controller
{
    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly TaxProfileService $profiles,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $profiles = $this->scoped($request)
            ->with('cfops')
            ->withCount(['products', 'productGroups'])
            ->when($request->boolean('only_active'), fn (Builder $query) => $query->where('active', true))
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return TaxProfileResource::collection($profiles);
    }

    public function show(Request $request, int $id): TaxProfileResource
    {
        return new TaxProfileResource($this->scoped($request)->with('cfops')->withCount(['products', 'productGroups'])->findOrFail($id));
    }

    public function templates(TaxProfileTemplates $templates): JsonResponse
    {
        return response()->json(['data' => $templates->all(), 'disclaimer' => TaxProfileTemplates::DISCLAIMER]);
    }

    public function store(StoreTaxProfileRequest $request): JsonResponse
    {
        $this->authorizeWrite($request);
        $ownership = $this->scope->ownershipFor($request->user());
        $profile = $this->profiles->create(new FiscalOwner($ownership['organization_id'], $ownership['professional_id']), $request->validated());

        return (new TaxProfileResource($profile))->additional(['message' => 'Perfil tributário criado.'])->response()->setStatusCode(201);
    }

    public function update(StoreTaxProfileRequest $request, int $id): TaxProfileResource
    {
        $this->authorizeWrite($request);

        return new TaxProfileResource($this->profiles->update($this->scoped($request)->findOrFail($id), $request->validated()));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->authorizeWrite($request);
        $this->profiles->delete($this->scoped($request)->findOrFail($id));

        return response()->json(['message' => 'Perfil tributário removido.']);
    }

    /**
     * Papel Spatie é global (o mesmo usuário pode ser dono em outra clínica): além da permissão da
     * rota, só o DONO desta organização escreve — mesma régua de `fiscal-settings`
     * (`FiscalDocumentPolicy::create`).
     */
    private function authorizeWrite(Request $request): void
    {
        Gate::forUser($request->user())->authorize('create', FiscalDocument::class);
    }

    /** @return Builder<TaxProfile> */
    private function scoped(Request $request): Builder
    {
        return $this->scope->scopeQuery(TaxProfile::query(), $request->user());
    }
}
