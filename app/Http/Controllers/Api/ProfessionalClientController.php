<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Models\PetVetAccess;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

class ProfessionalClientController extends Controller
{
    use PaginatesResults;

    /**
     * The client list feeds the appointment and invoice form selects, which load
     * it in full — hence a page large enough to hold a whole client book.
     */
    private const DEFAULT_PER_PAGE = 200;

    /**
     * Display a listing of the resource.
     *
     * A "client" of the professional is any user that:
     *   - has appointments with this professional, OR
     *   - has invoices with this professional, OR
     *   - is the tutor of a pet to which this professional holds an active PetVetAccess grant.
     */
    public function index(Request $request)
    {
        $professionalId = $request->user()->id;

        $clients = User::where('id', '!=', $professionalId)
            ->where(function ($query) use ($professionalId) {
                $query->whereHas('appointmentsAsClient', function ($q) use ($professionalId) {
                    $q->where('professional_id', $professionalId);
                })
                    ->orWhereHas('invoicesAsClient', function ($q) use ($professionalId) {
                        $q->where('professional_id', $professionalId);
                    })
                    ->orWhereIn('id', $this->tutorIdsWithActiveGrantTo($professionalId));
            })
            ->with('pets')
            ->orderBy('name')
            ->paginate($this->resolvePerPage($request, self::DEFAULT_PER_PAGE));

        return JsonResource::collection($clients);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // Validate and create a new client (user)
        // This might be complex as it involves user registration
        // For now, we can just create a user with a default password

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
        ]);

        $client = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => bcrypt('password'), // Default password
            // 'role' => 'client', // If we had roles
        ]);

        return response()->json($client, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $professionalId = Auth::id();

        $client = User::where('id', $id)
            ->where(function ($query) use ($professionalId) {
                $query->whereHas('appointmentsAsClient', function ($q) use ($professionalId) {
                    $q->where('professional_id', $professionalId);
                })
                    ->orWhereHas('invoicesAsClient', function ($q) use ($professionalId) {
                        $q->where('professional_id', $professionalId);
                    })
                    ->orWhereIn('id', $this->tutorIdsWithActiveGrantTo($professionalId));
            })
            ->with('pets')
            ->firstOrFail();

        return response()->json($client);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $professionalId = Auth::id();

        $client = User::where('id', $id)
            ->where(function ($query) use ($professionalId) {
                $query->whereHas('appointmentsAsClient', function ($q) use ($professionalId) {
                    $q->where('professional_id', $professionalId);
                })
                    ->orWhereHas('invoicesAsClient', function ($q) use ($professionalId) {
                        $q->where('professional_id', $professionalId);
                    });
            })
            ->firstOrFail();

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|string|email|max:255|unique:users,email,'.$id,
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
        ]);

        $client->update($validated);

        return response()->json($client);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $professionalId = Auth::id();

        // Ensure the client belongs to this professional before "deleting" (maybe just unlinking?)
        // For now, we keep the delete but scope it.
        $client = User::where('id', $id)
            ->where(function ($query) use ($professionalId) {
                $query->whereHas('appointmentsAsClient', function ($q) use ($professionalId) {
                    $q->where('professional_id', $professionalId);
                })
                    ->orWhereHas('invoicesAsClient', function ($q) use ($professionalId) {
                        $q->where('professional_id', $professionalId);
                    });
            })
            ->firstOrFail();

        $client->delete();

        return response()->json(null, 204);
    }

    /**
     * Get all pets for a specific client — only the pets this professional is
     * allowed to see (owner + either appointment history, invoice history, OR
     * a specific PetVetAccess grant for that pet).
     */
    public function pets(string $id)
    {
        $professionalId = Auth::id();

        $client = User::where('id', $id)
            ->where(function ($query) use ($professionalId) {
                $query->whereHas('appointmentsAsClient', function ($q) use ($professionalId) {
                    $q->where('professional_id', $professionalId);
                })
                    ->orWhereHas('invoicesAsClient', function ($q) use ($professionalId) {
                        $q->where('professional_id', $professionalId);
                    })
                    ->orWhereIn('id', $this->tutorIdsWithActiveGrantTo($professionalId));
            })
            ->firstOrFail();

        // Scope to pets the professional actually has a grant for (or any pet of
        // this tutor when the relationship is via appointment/invoice history).
        $grantedPetIds = PetVetAccess::query()
            ->where('veterinarian_id', $professionalId)
            ->active()
            ->pluck('pet_id')
            ->all();

        $pets = $client->pets()
            ->where(function ($q) use ($grantedPetIds) {
                // If there are granted pet IDs, allow those; otherwise fall back to all of
                // the tutor's pets (appointment/invoice-based relationship).
                if (! empty($grantedPetIds)) {
                    $q->whereIn('id', $grantedPetIds);
                }
            })
            ->get();

        return response()->json($pets);
    }

    /**
     * Subquery-style helper returning tutor IDs whose pets have an active grant for this professional.
     */
    private function tutorIdsWithActiveGrantTo(int $professionalId)
    {
        return PetVetAccess::query()
            ->where('veterinarian_id', $professionalId)
            ->active()
            ->join('pets', 'pets.id', '=', 'pet_vet_accesses.pet_id')
            ->distinct()
            ->pluck('pets.user_id');
    }
}
