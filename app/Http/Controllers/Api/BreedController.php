<?php

namespace App\Http\Controllers\Api;

use App\Enums\PetSpecies;
use App\Http\Controllers\Controller;
use App\Models\Breed;
use App\Services\ReferenceData\ReferenceDataCacheService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BreedController extends Controller
{
    public function __construct(
        private readonly ReferenceDataCacheService $referenceDataCacheService,
    ) {}

    /**
     * Lista todas as racas, com filtro opcional por especie.
     *
     * GET /api/breeds?species=dog
     * GET /api/breeds?search=labrador
     *
     * Cacheado 24h (Fase 9 do plano de otimizacao): raca muda raramente e nao pagina
     * — 500 linhas vindas do Redis e a resposta correta e barata.
     */
    public function index(Request $request): JsonResponse
    {
        $species = $request->filled('species') ? $request->input('species') : null;
        $search = $request->filled('search') ? $request->input('search') : null;

        $breeds = $this->referenceDataCacheService->remember(
            (new Breed)->getTable(),
            ['species' => $species, 'search' => $search],
            fn () => $this->queryBreeds($species, $search),
        );

        return response()->json([
            'data' => $breeds,
        ]);
    }

    private function queryBreeds(?string $species, ?string $search): Collection
    {
        $query = Breed::query();

        $speciesEnum = $species !== null ? PetSpecies::tryFrom($species) : null;
        if ($speciesEnum !== null) {
            $query->bySpecies($speciesEnum);
        }

        if ($search !== null) {
            $query->search($search);
        }

        return $query->orderBy('name')->get();
    }
}
