<?php

namespace App\Http\Controllers\Api\Public;

use App\Enums\ImmunizationGroup;
use App\Http\Controllers\Controller;
use App\Http\Resources\ImmunizationProductResource;
use App\Models\DietaryRestriction;
use App\Models\FoodAllergy;
use App\Models\FoodBrand;
use App\Models\ImmunizationProduct;
use App\Models\Pathology;
use App\Models\Specialty;
use App\Services\ReferenceData\ReferenceDataCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dado de referencia (patologias, vacinas, marcas de racao, especialidades, alergias
 * e restricoes alimentares): baixo volume, muda raramente, e nunca especifico de um
 * tutor/pet — por isso cacheado com TTL de 24h (Fase 9 do plano de otimizacao). Escopo
 * explicitamente autorizado pelo usuario; pet/prontuario/agenda continuam proibidos.
 */
class MasterDataController extends Controller
{
    public function __construct(
        private readonly ReferenceDataCacheService $referenceDataCacheService,
    ) {}

    public function pathologies(Request $request): JsonResponse
    {
        $species = $request->input('species');

        $pathologies = $this->referenceDataCacheService->remember(
            (new Pathology)->getTable(),
            ['species' => $species],
            fn () => Pathology::query()
                ->when($species !== null, fn ($query) => $query->where('species', $species))
                ->orderBy('name')
                ->get(),
        );

        return response()->json($pathologies);
    }

    /**
     * Lê de `immunization_products` (`group = vaccine`, catálogo global) — substitui o
     * legado `vaccine_catalog` (contrato `docs/gap-simplesvet/contratos/13-contrato-api.md`).
     * `vaccine_catalog`/`VaccineCatalog` seguem existindo só para histórico, não são mais
     * lidos por este endpoint.
     */
    public function vaccineCatalog(Request $request): JsonResponse
    {
        $species = $request->input('species');

        $vaccines = $this->referenceDataCacheService->remember(
            (new ImmunizationProduct)->getTable(),
            ['group' => ImmunizationGroup::VACCINE->value, 'species' => $species],
            fn () => ImmunizationProduct::query()
                ->where('group', ImmunizationGroup::VACCINE)
                ->whereNull('organization_id')
                ->with('speciesLinks')
                ->when(
                    $species !== null,
                    fn ($query) => $query->whereHas(
                        'speciesLinks',
                        fn ($speciesQuery) => $speciesQuery->where('species', $species),
                    ),
                )
                ->orderBy('name')
                ->get()
                ->map(fn (ImmunizationProduct $product) => (new ImmunizationProductResource($product))->resolve()),
        );

        return response()->json($vaccines);
    }

    public function foodBrands(Request $request): JsonResponse
    {
        $type = $request->input('type');
        $species = $request->input('species');

        $foodBrands = $this->referenceDataCacheService->remember(
            (new FoodBrand)->getTable(),
            ['type' => $type, 'species' => $species],
            fn () => FoodBrand::query()
                ->when($type !== null, fn ($query) => $query->where('type', $type))
                ->when($species !== null, fn ($query) => $query->where('species_target', $species))
                ->orderBy('name')
                ->get(),
        );

        return response()->json($foodBrands);
    }

    public function specialties(): JsonResponse
    {
        $specialties = $this->referenceDataCacheService->remember(
            (new Specialty)->getTable(),
            [],
            fn () => Specialty::orderBy('name')->get(),
        );

        return response()->json($specialties);
    }

    public function foodAllergies(): JsonResponse
    {
        $foodAllergies = $this->referenceDataCacheService->remember(
            (new FoodAllergy)->getTable(),
            [],
            fn () => FoodAllergy::orderBy('name')->get(),
        );

        return response()->json($foodAllergies);
    }

    public function dietaryRestrictions(): JsonResponse
    {
        $dietaryRestrictions = $this->referenceDataCacheService->remember(
            (new DietaryRestriction)->getTable(),
            [],
            fn () => DietaryRestriction::orderBy('name')->get(),
        );

        return response()->json($dietaryRestrictions);
    }
}
