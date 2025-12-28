<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pet;
use App\Services\PetCard\PetCardService;
use App\Services\PetCard\QRCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PetCardController extends Controller
{
    public function __construct(
        private readonly PetCardService $petCardService,
        private readonly QRCodeService $qrCodeService
    ) {}

    public function getQRCode(Request $request, int $petId): Response
    {
        $pet = Pet::where('id', $petId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $qrCodeUrl = $this->qrCodeService->generateQRCodeUrl(
            $this->qrCodeService->generatePetCardUrl($pet->public_id)
        );

        return response()->json([
            'qr_code_url' => $qrCodeUrl,
            'card_url' => $this->qrCodeService->generatePetCardUrl($pet->public_id),
        ]);
    }

    public function markLost(Request $request, int $petId): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string|max:500',
        ]);

        $pet = Pet::where('id', $petId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $this->petCardService->markAsLost($pet, $validated['message']);

        return response()->json(['message' => 'Pet marked as lost']);
    }

    public function markFound(Request $request, int $petId): JsonResponse
    {
        $pet = Pet::where('id', $petId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $this->petCardService->markAsFound($pet);

        return response()->json(['message' => 'Pet marked as found']);
    }
}

