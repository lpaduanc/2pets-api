<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProfessionalSearchResource;
use App\Http\Resources\PublicProfessionalSearchResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class ProfessionalController extends Controller
{
    public function show(string $id): JsonResponse
    {
        $professional = User::where('id', $id)
            ->visibleProfessional()
            ->with(['professional', 'professional.services'])
            ->first();

        if (! $professional) {
            return response()->json([
                'message' => 'Professional not found',
            ], 404);
        }

        return response()->json([
            'data' => $this->resourceFor($professional),
        ]);
    }

    /**
     * The full profile page is public by design (share links, SEO, the "veja o perfil
     * completo" conversion hook from CLAUDE.md) — this route has no `auth:sanctum`
     * middleware, so an anonymous visitor must still get a 200. Contact (email/phone) is
     * what has to stay login-gated, matching the search cards (`PublicProfessionalSearchResource`)
     * and the frontend's own `isAuthenticated` gate on booking/messaging for this same page.
     *
     * `auth('sanctum')->user()` resolves the bearer token directly against the guard and
     * returns null silently when absent (`Laravel\Sanctum\Guard`) — it never throws, so it's
     * safe to call outside an `auth:sanctum` middleware group.
     */
    private function resourceFor(User $professional): ProfessionalSearchResource
    {
        return auth('sanctum')->user() !== null
            ? new ProfessionalSearchResource($professional)
            : new PublicProfessionalSearchResource($professional);
    }
}
