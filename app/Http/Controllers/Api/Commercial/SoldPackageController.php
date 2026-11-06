<?php

namespace App\Http\Controllers\Api\Commercial;

use App\Enums\SoldPackageLifecycleStatus;
use App\Enums\SoldPackageStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commercial\ConsumeSoldPackageRequest;
use App\Http\Resources\Commercial\SoldPackageResource;
use App\Models\SoldPackage;
use App\Models\User;
use App\Services\Commercial\Authorization\SalePermissionGate;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Commercial\Package\SoldPackageProfitability;
use App\Services\Commercial\Package\SoldPackageValidityService;
use App\Services\Commercial\SoldPackageService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
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
        private readonly SalePermissionGate $permissions,
        private readonly SoldPackageValidityService $validity,
        private readonly SoldPackageProfitability $profitability,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(SoldPackageStatus::class)],
            'lifecycle_status' => ['nullable', Rule::enum(SoldPackageLifecycleStatus::class)],
        ]);

        $query = $this->scopedQuery($request->user())->with(SoldPackageResource::RESOURCE_RELATIONS);

        $this->applyFilters($query, $request);
        $packages = $query->orderByDesc('sold_at')->get();

        // Status da tela é derivado (PLANO 2.7): filtrado depois da leitura, no mesmo cálculo do resource.
        if ($request->filled('lifecycle_status')) {
            $wanted = SoldPackageLifecycleStatus::from($request->string('lifecycle_status')->toString());
            $packages = $packages->filter(fn (SoldPackage $p): bool => $p->lifecycleStatus($p->organization?->package_expiring_soon_days ?? 30) === $wanted)->values();
        }

        return SoldPackageResource::collection($packages);
    }

    public function show(Request $request, int $id): SoldPackageResource
    {
        $package = $this->scopedQuery($request->user())
            ->with([...SoldPackageResource::RESOURCE_RELATIONS, 'validityExtensions.user:id,name'])
            ->findOrFail($id);

        return (new SoldPackageResource($package))->additional(['validity_extensions' => $package->validityExtensions->map(fn ($log): array => [
            'id' => $log->id,
            'from_date' => $log->from_date?->toDateString(),
            'to_date' => $log->to_date->toDateString(),
            'reason' => $log->reason,
            'user' => $log->user === null ? null : ['id' => $log->user->id, 'name' => $log->user->name],
            'created_at' => $log->created_at?->toIso8601String(),
        ])->values()]);
    }

    /** `POST sold-packages/{id}/extend-validity` (PLANO 2.7): `packages.extend_validity` no cargo da organização do pacote. */
    public function extendValidity(Request $request, int $id): SoldPackageResource
    {
        $package = $this->scopedQuery($request->user())->findOrFail($id);
        abort_unless(
            $this->permissions->allowsInOrganization($request->user(), $package->organization_id, SalePermissionGate::EXTEND_PACKAGE_VALIDITY),
            403,
            'Você não tem permissão para prorrogar a validade de pacotes.',
        );
        $data = $request->validate(['expires_at' => ['required', 'date'], 'reason' => ['required', 'string', 'min:3', 'max:500']]);

        $this->validity->extend($package, $data['expires_at'], $data['reason'], $request->user());

        return (new SoldPackageResource($package->fresh(SoldPackageResource::RESOURCE_RELATIONS)))
            ->additional(['message' => 'Validade do pacote prorrogada.']);
    }

    /** `GET sold-packages/{id}/profitability` (PLANO 2.7): rentabilidade sessão a sessão; mostra custo. */
    public function profitability(Request $request, int $id): JsonResponse
    {
        $package = $this->scopedQuery($request->user())->findOrFail($id);
        abort_unless(
            $this->permissions->allowsInOrganization($request->user(), $package->organization_id, SalePermissionGate::PROFITABILITY),
            403,
            'Você não tem permissão para ver a rentabilidade deste pacote.',
        );

        return response()->json(['data' => $this->profitability->report($package)]);
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
