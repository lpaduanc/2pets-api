<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Document\UpdateProfessionalLegalProfileRequest;
use App\Http\Requests\Document\UploadProfessionalSignatureRequest;
use App\Http\Resources\ProfessionalLegalProfileResource;
use App\Models\Professional;
use App\Services\Medical\ProfessionalLegalProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `me/professional-profile` — contrato docs/gap-simplesvet/contratos/15-contrato-api.md. */
class ProfessionalLegalProfileController extends Controller
{
    public function __construct(private readonly ProfessionalLegalProfileService $profileService) {}

    public function show(Request $request): JsonResponse
    {
        $professional = $this->resolveProfessional($request);

        return response()->json(['data' => new ProfessionalLegalProfileResource($professional)]);
    }

    public function update(UpdateProfessionalLegalProfileRequest $request): JsonResponse
    {
        $professional = $this->resolveProfessional($request);

        $professional = $this->profileService->updateProfile($professional, $request->validated());

        return response()->json(['data' => new ProfessionalLegalProfileResource($professional)]);
    }

    public function uploadSignature(UploadProfessionalSignatureRequest $request): JsonResponse
    {
        $professional = $this->resolveProfessional($request);

        $professional = $this->profileService->uploadSignature($professional, $request->validated('signature'));

        return response()->json(['data' => new ProfessionalLegalProfileResource($professional)]);
    }

    private function resolveProfessional(Request $request): Professional
    {
        $professional = $request->user()->professional;
        abort_if($professional === null, 404, 'Perfil profissional não encontrado.');

        return $professional;
    }
}
