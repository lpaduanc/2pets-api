<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    /**
     * Get authenticated user profile
     */
    public function profile(Request $request)
    {
        $user = $request->user()->load(['pets', 'professional', 'company']);

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'phone' => $user->phone,
            'address' => $user->address,
            'city' => $user->city,
            'state' => $user->state,
            'zip_code' => $user->zip_code,
            'google_id' => $user->google_id,
            'pets_count' => $user->pets->count(),
            'professional' => $user->professional,
            'company' => $user->company,
            'created_at' => $user->created_at->format('d/m/Y')
        ]);
    }

    /**
     * Update user profile
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|unique:users,email,' . $user->id,
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:2',
            'zip_code' => 'nullable|string|max:10',
            'current_password' => 'nullable|required_with:new_password',
            'new_password' => 'nullable|min:8|confirmed'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        // Check current password if changing password
        if (isset($data['current_password'])) {
            if (!Hash::check($data['current_password'], $user->password)) {
                return response()->json([
                    'errors' => ['current_password' => ['Senha atual incorreta']]
                ], 422);
            }

            $user->password = Hash::make($data['new_password']);
            unset($data['current_password'], $data['new_password'], $data['new_password_confirmation']);
        }

        // Update user data
        $user->update(array_filter($data, function ($key) {
            return !in_array($key, ['current_password', 'new_password', 'new_password_confirmation']);
        }, ARRAY_FILTER_USE_KEY));

        return response()->json([
            'message' => 'Perfil atualizado com sucesso!',
            'user' => $user
        ]);
    }

    /**
     * Get dashboard stats
     */
    public function dashboardStats(Request $request)
    {
        $user = $request->user();

        $stats = [
            'totalPets' => $user->pets()->count(),
            'appointments' => 0, // TODO: Implement appointments
            'healthRecords' => 0, // TODO: Implement health records
            'walletBalance' => 0 // TODO: Implement wallet
        ];

        return response()->json($stats);
    }
}
