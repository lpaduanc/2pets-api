<?php

namespace App\Http\Controllers\Api;

use App\Enums\VetAccessLevel;
use App\Http\Controllers\Controller;
use App\Http\Requests\PetVetAccess\GrantVetAccessRequest;
use App\Http\Resources\PetVetAccessResource;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PetVetAccessController extends Controller
{
    /**
     * Tutor concede acesso ao veterinário para visualizar dados do pet.
     *
     * Apenas o dono do pet pode conceder acesso.
     */
    public function grant(GrantVetAccessRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        $pet = Pet::findOrFail($data['pet_id']);

        // Verifica se o usuário autenticado é o dono do pet
        if ($pet->user_id !== $user->id) {
            return response()->json([
                'message' => 'Apenas o tutor dono do pet pode conceder acesso.',
            ], 403);
        }

        // Verifica se o veterinário existe e tem role adequada
        $vet = User::findOrFail($data['veterinarian_id']);
        if (!in_array($vet->role, ['veterinarian', 'vet_freelancer', 'clinic_vet'])) {
            return response()->json([
                'message' => 'O usuário informado não é um profissional veterinário.',
            ], 422);
        }

        // Verifica se já existe acesso ativo para este vet+pet
        $existingAccess = PetVetAccess::where('pet_id', $pet->id)
            ->where('veterinarian_id', $vet->id)
            ->where('is_active', true)
            ->first();

        if ($existingAccess) {
            return response()->json([
                'message' => 'Este veterinário já possui acesso ativo a este pet.',
                'data' => new PetVetAccessResource($existingAccess),
            ], 409);
        }

        $accessLevel = VetAccessLevel::tryFrom($data['access_level'] ?? 'read') ?? VetAccessLevel::READ;

        $access = PetVetAccess::create([
            'pet_id' => $pet->id,
            'veterinarian_id' => $vet->id,
            'granted_by' => $user->id,
            'access_level' => $accessLevel,
            'granted_at' => now(),
            'is_active' => true,
        ]);

        $access->load(['pet', 'veterinarian', 'grantor']);

        return response()->json([
            'message' => 'Acesso concedido com sucesso.',
            'data' => new PetVetAccessResource($access),
        ], 201);
    }

    /**
     * Tutor revoga acesso do veterinário ao pet.
     *
     * Apenas o dono do pet pode revogar acesso.
     */
    public function revoke(Request $request, int $accessId): JsonResponse
    {
        $user = $request->user();

        $access = PetVetAccess::with('pet')->findOrFail($accessId);

        // Verifica se o usuário autenticado é o dono do pet
        if ($access->pet->user_id !== $user->id) {
            return response()->json([
                'message' => 'Apenas o tutor dono do pet pode revogar acesso.',
            ], 403);
        }

        if (!$access->is_active) {
            return response()->json([
                'message' => 'Este acesso já foi revogado.',
            ], 422);
        }

        $access->revoke();

        $access->load(['pet', 'veterinarian', 'grantor']);

        return response()->json([
            'message' => 'Acesso revogado com sucesso.',
            'data' => new PetVetAccessResource($access),
        ]);
    }

    /**
     * Veterinário lista todos os pets aos quais tem acesso ativo.
     */
    public function myAccesses(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $accesses = PetVetAccess::with(['pet', 'grantor'])
            ->where('veterinarian_id', $user->id)
            ->where('is_active', true)
            ->orderByDesc('granted_at')
            ->paginate(20);

        return PetVetAccessResource::collection($accesses);
    }

    /**
     * Tutor lista todos os acessos veterinários concedidos para seus pets.
     */
    public function petAccesses(Request $request, int $petId): JsonResponse
    {
        $user = $request->user();

        $pet = Pet::findOrFail($petId);

        if ($pet->user_id !== $user->id) {
            return response()->json([
                'message' => 'Você não tem permissão para visualizar os acessos deste pet.',
            ], 403);
        }

        $accesses = PetVetAccess::with(['veterinarian', 'grantor'])
            ->where('pet_id', $pet->id)
            ->orderByDesc('is_active')
            ->orderByDesc('granted_at')
            ->get();

        return response()->json([
            'data' => PetVetAccessResource::collection($accesses),
        ]);
    }
}
