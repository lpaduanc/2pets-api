<?php

namespace App\Http\Controllers\Api;

use App\Enums\PetSpecies;
use App\Http\Controllers\Controller;
use App\Models\Breed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BreedController extends Controller
{
    /**
     * Lista todas as racas, com filtro opcional por especie.
     *
     * GET /api/breeds?species=dog
     * GET /api/breeds?search=labrador
     */
    public function index(Request $request): JsonResponse
    {
        $query = Breed::query();

        if ($request->filled('species')) {
            $species = PetSpecies::tryFrom($request->input('species'));
            if ($species) {
                $query->bySpecies($species);
            }
        }

        if ($request->filled('search')) {
            $query->search($request->input('search'));
        }

        $breeds = $query->orderBy('name')->get();

        return response()->json([
            'data' => $breeds,
        ]);
    }
}
