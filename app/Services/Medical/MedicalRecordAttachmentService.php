<?php

namespace App\Services\Medical;

use App\Models\MedicalRecord;
use App\Models\MedicalRecordAttachment;
use App\Services\FileUploadService;
use Illuminate\Http\UploadedFile;

/**
 * Upload/remoção de anexo de prontuário (item 9 do MVP). Reaproveita
 * `FileUploadService` (mesmo padrão de disco privado do `ExamService`).
 */
final class MedicalRecordAttachmentService
{
    public function __construct(
        private readonly FileUploadService $fileUploadService,
    ) {}

    public function attach(MedicalRecord $medicalRecord, UploadedFile $file, int $uploaderId): MedicalRecordAttachment
    {
        $path = $this->fileUploadService->uploadForMedicalRecordAttachment(
            $file,
            $medicalRecord->id,
            $uploaderId,
        );

        return $medicalRecord->attachments()->create([
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => $uploaderId,
        ]);
    }

    public function remove(MedicalRecordAttachment $attachment): void
    {
        $attachment->deletePhysicalFile($this->fileUploadService->privateDisk());
        $attachment->delete();
    }
}
