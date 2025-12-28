<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProfessionalSearchResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class ProfessionalController extends Controller
{
    public function show(string $id): JsonResponse
    {
        $professional = User::where('id', $id)
            ->where('role', 'professional')
            ->where('profile_completed', true)
            ->where('registration_status', 'approved')
            ->where('is_suspended', false)
            ->with(['professional', 'professional.services'])
            ->first();

        if (!$professional) {
            return response()->json([
                'message' => 'Professional not found'
            ], 404);
        }

        return response()->json([
            'data' => new ProfessionalSearchResource($professional)
        ]);
    }
}
