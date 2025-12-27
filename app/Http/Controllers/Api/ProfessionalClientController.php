<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProfessionalClientController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // In a real scenario, we would filter by clients associated with the professional
        // For now, we return all users with role 'client'
        // Or we can return users who have appointments with the current professional

        $professionalId = Auth::id();

        // Get clients who have appointments OR invoices with this professional
        $clients = User::where('id', '!=', $professionalId)
            ->where(function ($query) use ($professionalId) {
                $query->whereHas('appointmentsAsClient', function ($q) use ($professionalId) {
                    $q->where('professional_id', $professionalId);
                })
                    ->orWhereHas('invoicesAsClient', function ($q) use ($professionalId) {
                        $q->where('professional_id', $professionalId);
                    });
            })
            ->with('pets')
            ->get();

        return response()->json($clients);
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
                    });
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
            'email' => 'sometimes|string|email|max:255|unique:users,email,' . $id,
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
     * Get all pets for a specific client
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
                    });
            })
            ->firstOrFail();

        $pets = $client->pets;
        return response()->json($pets);
    }
}
