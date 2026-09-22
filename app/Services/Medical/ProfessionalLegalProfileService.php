<?php

namespace App\Services\Medical;

use App\Models\Professional;
use App\Services\FileUploadService;
use Illuminate\Http\UploadedFile;

/**
 * `me/professional-profile` — contrato docs/gap-simplesvet/specs/
 * 15-modelos-documento-receituario-assinatura-spec.md. `mapa_registration` nunca é
 * obrigatório para prescrição comum (regra de negócio 5) — este service só grava o que veio.
 */
final class ProfessionalLegalProfileService
{
    public function __construct(private readonly FileUploadService $fileUploadService) {}

    /**
     * @param  array{title?: ?string, mapa_registration?: ?string}  $data
     */
    public function updateProfile(Professional $professional, array $data): Professional
    {
        $professional->update(array_intersect_key($data, array_flip(['title', 'mapa_registration'])));

        return $professional->fresh();
    }

    public function uploadSignature(Professional $professional, UploadedFile $file): Professional
    {
        $path = $this->fileUploadService->uploadForProfessionalSignature($file, $professional->id);
        $professional->update(['signature_image_path' => $path]);

        return $professional->fresh();
    }
}
