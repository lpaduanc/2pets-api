<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesPetAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pet\StorePetWeightRequest;
use App\Models\PetWeightHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Weight history per pet. Reads are open to the tutor + any vet with an active grant;
 * writes require owner or a WRITE/FULL grant.
 */
class PetWeightController extends Controller
{
    use AuthorizesPetAccess;

    public function index(Request $request, int $petId): JsonResponse
    {
        $pet = $this->resolvePetForRead($request, $petId);

        $perPage = (int) $request->input('per_page', 50);

        $entries = PetWeightHistory::query()
            ->with('measuredBy:id,name,role')
            ->where('pet_id', $pet->id)
            ->orderByDesc('measured_at')
            ->paginate($perPage);

        return response()->json($entries);
    }

    public function store(StorePetWeightRequest $request, int $petId): JsonResponse
    {
        $pet = $this->resolvePetForWrite($request, $petId);

        $data = $request->validated();

        $entry = PetWeightHistory::create([
            'pet_id' => $pet->id,
            'measured_by_user_id' => $request->user()->id,
            'weight' => $data['weight'],
            'measured_at' => $data['measured_at'],
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json([
            'data' => $entry->fresh()->load('measuredBy:id,name,role'),
            'message' => 'Peso registrado com sucesso.',
        ], 201);
    }
}
