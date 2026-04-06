<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProfessionalSearchResource;
use App\Models\Favorite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $favorites = Favorite::where('user_id', $request->user()->id)
            ->with(['professional.professional', 'professional.professional.services'])
            ->latest()
            ->paginate(15);

        return response()->json([
            'data' => $favorites->through(fn ($fav) => [
                'id' => $fav->id,
                'professional' => new ProfessionalSearchResource($fav->professional),
                'created_at' => $fav->created_at,
            ]),
            'meta' => [
                'current_page' => $favorites->currentPage(),
                'last_page' => $favorites->lastPage(),
                'total' => $favorites->total(),
            ],
        ]);
    }

    public function toggle(Request $request, int $professionalId): JsonResponse
    {
        $userId = $request->user()->id;

        $existing = Favorite::where('user_id', $userId)
            ->where('professional_id', $professionalId)
            ->first();

        if ($existing) {
            $existing->delete();
            return response()->json([
                'favorited' => false,
                'message' => 'Profissional removido dos favoritos',
            ]);
        }

        Favorite::create([
            'user_id' => $userId,
            'professional_id' => $professionalId,
        ]);

        return response()->json([
            'favorited' => true,
            'message' => 'Profissional adicionado aos favoritos',
        ], 201);
    }

    public function check(Request $request, int $professionalId): JsonResponse
    {
        $favorited = Favorite::where('user_id', $request->user()->id)
            ->where('professional_id', $professionalId)
            ->exists();

        return response()->json(['favorited' => $favorited]);
    }
}
