<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Pet;
use App\Services\PetCard\PetCardService;
use Illuminate\Http\JsonResponse;

class PetCardController extends Controller
{
    public function __construct(
        private readonly PetCardService $petCardService
    ) {}

    public function show(string $publicId): JsonResponse
    {
        $pet = Pet::where('public_id', $publicId)
            ->with(['user', 'vaccinations'])
            ->firstOrFail();

        $cardData = $this->petCardService->getPublicCardData($pet);

        return response()->json(['data' => $cardData]);
    }
}

