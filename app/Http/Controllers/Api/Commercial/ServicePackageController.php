<?php

namespace App\Http\Controllers\Api\Commercial;

use App\Http\Controllers\Controller;
use App\Http\Requests\Commercial\StoreServicePackageRequest;
use App\Http\Resources\Commercial\ServicePackageResource;
use App\Models\Service;
use App\Models\ServicePackage;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Commercial\Package\PackageSessionPricer;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Catálogo de pacotes de serviços — contrato
 * docs/gap-simplesvet/specs/10-pacotes-de-servicos-vendidos-spec.md.
 *
 * `sold_packages` já vendidos não são afetados por uma edição no catálogo: o saldo de quem já
 * comprou é uma cópia (`sold_package_items`), não uma referência viva à composição de hoje.
 */
class ServicePackageController extends Controller
{
    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly PackageSessionPricer $pricer,
    ) {}

    /**
     * Prévia ao montar o pacote (PLANO 2.7), sem gravar: soma avulsa dos serviços (preço de
     * hoje), desconto em R$ e %, e o valor de cada sessão pela regra do rateio.
     */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'price' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.service_id' => ['required', 'integer', 'distinct', 'exists:services,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);
        $this->assertServicesAreVisible($request, $data['items']);
        $prices = Service::query()->whereIn('id', array_column($data['items'], 'service_id'))->pluck('price', 'id');

        $result = $this->pricer->price(Money::of($data['price'])->toString(), array_map(fn (array $item): array => [
            'service_id' => (int) $item['service_id'],
            'unit_price' => (string) ($prices[$item['service_id']] ?? '0'),
            'quantity' => (int) $item['quantity'],
        ], $data['items']));

        return response()->json(['data' => $result]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $packages = $this->scopedQuery($request->user())
            ->with(ServicePackageResource::RESOURCE_RELATIONS)
            ->orderBy('name')
            ->get();

        return ServicePackageResource::collection($packages);
    }

    public function store(StoreServicePackageRequest $request): JsonResponse
    {
        $this->assertServicesAreVisible($request, $request->array('items'));

        $package = DB::transaction(function () use ($request): ServicePackage {
            $package = ServicePackage::create(
                $this->fieldsFrom($request) + $this->scope->ownershipFor($request->user())
            );

            $this->syncItems($package, $request->array('items'));

            return $package;
        });

        return (new ServicePackageResource($package->load(ServicePackageResource::RESOURCE_RELATIONS)))
            ->additional(['message' => 'Pacote cadastrado com sucesso.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, int $id): ServicePackageResource
    {
        $package = $this->scopedQuery($request->user())
            ->with(ServicePackageResource::RESOURCE_RELATIONS)
            ->findOrFail($id);

        return new ServicePackageResource($package);
    }

    public function update(StoreServicePackageRequest $request, int $id): ServicePackageResource
    {
        $package = $this->scopedQuery($request->user())->findOrFail($id);

        if ($request->has('items')) {
            $this->assertServicesAreVisible($request, $request->array('items'));
        }

        DB::transaction(function () use ($request, $package): void {
            $package->update($this->fieldsFrom($request));

            if ($request->has('items')) {
                $this->syncItems($package, $request->array('items'));
            }
        });

        return new ServicePackageResource($package->fresh(ServicePackageResource::RESOURCE_RELATIONS));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $package = $this->scopedQuery($request->user())
            ->withCount('soldPackages')
            ->findOrFail($id);

        // Pacote já vendido não some do catálogo: o histórico de quem comprou (`sold_packages`)
        // continua apontando para ele, e apagar quebraria a tela de consulta do cliente.
        if ($package->sold_packages_count > 0) {
            return response()->json([
                'message' => 'Pacote já vendido para '.$package->sold_packages_count.' cliente(s). Desative em vez de excluir.',
            ], 422);
        }

        $package->delete();

        return response()->json(['message' => 'Pacote removido.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function fieldsFrom(StoreServicePackageRequest $request): array
    {
        $data = $request->validated();
        unset($data['items']);

        return $data;
    }

    /**
     * Substitui a composição inteira — mesmo padrão de `PurchaseController` para itens de
     * rascunho: o front sempre manda a lista completa, nunca um diff.
     *
     * @param  list<array{service_id: int, quantity: int}>  $items
     */
    private function syncItems(ServicePackage $package, array $items): void
    {
        $package->items()->delete();

        foreach ($items as $item) {
            $package->items()->create([
                'service_id' => $item['service_id'],
                'quantity' => $item['quantity'],
            ]);
        }
    }

    /**
     * `exists:services,id` só garante que o serviço existe, não que é visível para quem chamou
     * — mesma armadilha corrigida em `ProductGroupController::assertParentIsVisible`.
     *
     * @param  list<array{service_id: int, quantity: int}>  $items
     */
    private function assertServicesAreVisible(Request $request, array $items): void
    {
        $serviceIds = array_column($items, 'service_id');
        $visibleCount = $this->scope->scopeQuery(Service::query(), $request->user())
            ->whereIn('id', $serviceIds)
            ->count();

        if ($visibleCount !== count(array_unique($serviceIds))) {
            throw ValidationException::withMessages([
                'items' => 'Um ou mais serviços não pertencem ao catálogo desta clínica.',
            ]);
        }
    }

    /**
     * @return Builder<ServicePackage>
     */
    private function scopedQuery(User $user): Builder
    {
        return $this->scope->scopeQuery(ServicePackage::query(), $user);
    }
}
