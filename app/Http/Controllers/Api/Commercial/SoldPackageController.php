<?php

namespace App\Http\Controllers\Api\Commercial;

use App\Enums\SoldPackageStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commercial\ConsumeSoldPackageRequest;
use App\Http\Resources\Commercial\SoldPackageResource;
use App\Models\SoldPackage;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Commercial\SoldPackageService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Consulta e consumo de pacote vendido — contrato
 * docs/gap-simplesvet/specs/10-pacotes-de-servicos-vendidos-spec.md.
 *
 * Sem `cancel` próprio de propósito: cancelamento de pacote é efeito colateral de
 * `SaleService::cancel()` (`SoldPackageService::cancelForSale`), nunca uma ação isolada — evita
 * o estado inconsistente "venda ativa, pacote cancelado".
 */
class SoldPackageController extends Controller
{
    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly SoldPackageService $soldPackages,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['status' => ['nullable', Rule::enum(SoldPackageStatus::class)]]);

        $query = $this->scopedQuery($request->user())->with(SoldPackageResource::RESOURCE_RELATIONS);

        $this->applyFilters($query, $request);

        return SoldPackageResource::collection($query->orderByDesc('sold_at')->get());
    }

    public function show(Request $request, int $id): SoldPackageResource
    {
        $package = $this->scopedQuery($request->user())
            ->with(SoldPackageResource::RESOURCE_RELATIONS)
            ->findOrFail($id);

        return new SoldPackageResource($package);
    }

    public function consume(ConsumeSoldPackageRequest $request, int $id): SoldPackageResource
    {
        $package = $this->scopedQuery($request->user())->with('items')->findOrFail($id);

        $this->soldPackages->consume($package, $request->validated(), $request->user());

        return new SoldPackageResource($package->fresh(SoldPackageResource::RESOURCE_RELATIONS));
    }

    /**
     * O que PDV e agenda consultam antes de oferecer "usar pacote" ao balconista: só pacote
     * ATIVO com saldo em pelo menos um serviço, do próprio pet (ou de outro pet do mesmo
     * catálogo com `allow_transfer_between_pets=true` — regra de negócio 2 do doc).
     */
    public function availableForPet(Request $request, int $petId): AnonymousResourceCollection
    {
        $packages = $this->scopedQuery($request->user())
            ->with(SoldPackageResource::RESOURCE_RELATIONS)
            ->where(function (Builder $q) use ($petId): void {
                $q->where('pet_id', $petId)
                    ->orWhere(function (Builder $transferable) use ($petId): void {
                        $transferable->whereHas('servicePackage', fn (Builder $sp) => $sp->where('allow_transfer_between_pets', true))
                            ->whereHas('client.pets', fn (Builder $pets) => $pets->whereKey($petId));
                    });
            })
            ->whereEffectiveStatus(SoldPackageStatus::ACTIVE)
            ->get();

        return SoldPackageResource::collection($packages);
    }

    public function forClient(Request $request, int $clientId): AnonymousResourceCollection
    {
        $packages = $this->scopedQuery($request->user())
            ->with(SoldPackageResource::RESOURCE_RELATIONS)
            ->where('client_id', $clientId)
            ->orderByDesc('sold_at')
            ->get();

        return SoldPackageResource::collection($packages);
    }

    /**
     * @param  Builder<SoldPackage>  $query
     */
    private function applyFilters(Builder $query, Request $request): void
    {
        $query
            ->when($request->filled('client_id'), fn (Builder $q) => $q->where('client_id', $request->integer('client_id')))
            ->when($request->filled('pet_id'), fn (Builder $q) => $q->where('pet_id', $request->integer('pet_id')))
            ->when($request->filled('expires_before'), fn (Builder $q) => $q->whereDate('expires_at', '<=', $request->date('expires_before')))
            ->when($request->filled('status'), function (Builder $q) use ($request): void {
                $status = SoldPackageStatus::from($request->string('status')->toString());
                $q->whereEffectiveStatus($status);
            });
    }

    /**
     * @return Builder<SoldPackage>
     */
    private function scopedQuery(User $user): Builder
    {
        return $this->scope->scopeQuery(SoldPackage::query(), $user);
    }
}
