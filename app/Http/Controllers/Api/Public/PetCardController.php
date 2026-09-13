<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicPetCardResource;
use App\Models\Pet;

/**
 * GET /api/public/pet-card/{publicId} — no `auth:sanctum` on purpose. This is
 * the destination of the QR code printed on a pet's carteirinha: whoever
 * finds a lost animal scans it without ever logging in.
 */
class PetCardController extends Controller
{
    public function show(string $publicId): PublicPetCardResource
    {
        $pet = Pet::query()
            ->select(['id', 'user_id', 'public_id', 'name', 'species', 'breed', 'image_url', 'is_lost', 'lost_alert_message'])
            ->where('public_id', $publicId)
            ->with(['user:id,name,phone'])
            ->firstOrFail();

        return new PublicPetCardResource($pet);
    }
}
