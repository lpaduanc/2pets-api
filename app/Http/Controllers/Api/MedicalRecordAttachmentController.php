<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\DownloadsPrivateFile;
use App\Http\Controllers\Controller;
use App\Http\Requests\MedicalRecord\StoreMedicalRecordAttachmentRequest;
use App\Http\Resources\MedicalRecordAttachmentResource;
use App\Models\MedicalRecord;
use App\Models\MedicalRecordAttachment;
use App\Services\FileUploadService;
use App\Services\Medical\MedicalRecordAttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Anexos de prontuário (item 9 do MVP,
 * docs/atendimento-veterinario/01-contrato-api-e-taxonomia.md §1/§2). Separado de
 * `ConsultationController` de propósito: é I/O de arquivo, não estado do atendimento — mantém
 * as duas classes dentro do limite de 200 linhas para classe nova.
 */
class MedicalRecordAttachmentController extends Controller
{
    use DownloadsPrivateFile;

    public function __construct(
        private readonly MedicalRecordAttachmentService $attachmentService,
        private readonly FileUploadService $fileUploadService,
    ) {}

    /** POST professional/medical-records/{id}/attachments */
    public function store(StoreMedicalRecordAttachmentRequest $request, int $id): JsonResponse
    {
        $record = MedicalRecord::findOrFail($id);
        Gate::forUser($request->user())->authorize('manageAttachment', $record);

        $attachment = $this->attachmentService->attach(
            $record,
            $request->file('file'),
            $request->user()->id
        );

        return (new MedicalRecordAttachmentResource($attachment))
            ->additional(['message' => 'Anexo enviado com sucesso!'])
            ->response()
            ->setStatusCode(201);
    }

    /** DELETE professional/medical-records/{id}/attachments/{attachmentId} */
    public function destroy(Request $request, int $id, int $attachmentId): JsonResponse
    {
        $record = MedicalRecord::findOrFail($id);
        Gate::forUser($request->user())->authorize('removeAttachment', $record);

        $attachment = MedicalRecordAttachment::where('medical_record_id', $record->id)->findOrFail($attachmentId);
        $this->attachmentService->remove($attachment);

        return response()->json(['message' => 'Anexo removido com sucesso!']);
    }

    /**
     * GET medical-records/{id}/attachments/{attachmentId}/download
     *
     * Fora do prefixo `professional` de propósito: tutor e vet com `PetVetAccess` também
     * baixam anexo de prontuário finalizado (contrato §6 — direito de cópia integral).
     * Mesmo padrão de `ExamController::downloadImage`.
     */
    public function download(Request $request, int $id, int $attachmentId): JsonResponse|StreamedResponse
    {
        $record = MedicalRecord::findOrFail($id);
        Gate::forUser($request->user())->authorize('view', $record);

        $attachment = MedicalRecordAttachment::where('medical_record_id', $record->id)->findOrFail($attachmentId);
        $disk = Storage::disk($this->fileUploadService->privateDisk());

        $this->assertFileExistsOnDisk($disk, $attachment->path, self::class);

        return $this->downloadResponse($disk, $attachment->path, $attachment->original_name, $attachment->mime);
    }
}
