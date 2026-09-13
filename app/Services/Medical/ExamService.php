<?php

namespace App\Services\Medical;

use App\DataTransferObjects\ExamReferenceRange;
use App\DataTransferObjects\ExamResultValue;
use App\Enums\ExamResultStatus;
use App\Models\Exam;
use App\Models\ExamImage;
use App\Models\Pet;
use App\Models\User;
use App\Services\FileUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

final class ExamService
{
    public function __construct(
        private readonly FileUploadService $fileUploadService
    ) {}

    public function createExam(
        Pet $pet,
        User $professional,
        string $examType,
        string $examName,
        \Carbon\Carbon $examDate,
        ?string $notes = null,
        ?int $appointmentId = null
    ): Exam {
        return Exam::create([
            'pet_id' => $pet->id,
            'professional_id' => $professional->id,
            'appointment_id' => $appointmentId,
            'exam_type' => $examType,
            'exam_name' => $examName,
            'exam_date' => $examDate,
            'notes' => $notes,
        ]);
    }

    /**
     * @param  array<int, array{parameter: string, value: string, unit?: ?string, reference_range?: ?string, status?: ?string}>  $results
     */
    public function addResults(Exam $exam, array $results): void
    {
        foreach ($results as $result) {
            $this->addResult($exam, $result);
        }

        if (! $exam->isCompleted()) {
            $exam->complete();
        }

        // TODO: Notify pet owner about results
    }

    /**
     * Guarda o texto original do resultado (`value`, `reference_range`) e, quando
     * parseável, também o numérico (`value_numeric`, `reference_min`/`reference_max`) —
     * ver `App\DataTransferObjects\PtBrDecimal` para a heurística de parsing em pt-BR.
     *
     * @param  array{parameter: string, value: string, unit?: ?string, reference_range?: ?string, status?: ?string}  $result
     */
    private function addResult(Exam $exam, array $result): void
    {
        $value = new ExamResultValue($result['value']);
        $range = new ExamReferenceRange($result['reference_range'] ?? null);

        $exam->results()->create([
            'parameter' => $result['parameter'],
            'value' => $value->raw,
            'value_numeric' => $value->numeric,
            'unit' => $result['unit'] ?? null,
            'reference_range' => $result['reference_range'] ?? null,
            'reference_min' => $range->min,
            'reference_max' => $range->max,
            'status' => $this->resolveStatus($result['status'] ?? null, $value, $range),
        ]);
    }

    /**
     * Um status informado explicitamente pelo operador sempre vence — o cálculo automático
     * só entra quando ninguém marcou nada à mão.
     */
    private function resolveStatus(?string $manualStatus, ExamResultValue $value, ExamReferenceRange $range): ?ExamResultStatus
    {
        if ($manualStatus !== null) {
            return ExamResultStatus::from($manualStatus);
        }

        return ExamResultStatus::deriveFrom($value->numeric, $range->min, $range->max);
    }

    /**
     * Attach uploaded files (PDFs / images) to an exam.
     *
     * Files are stored on a PRIVATE disk. Controllers must have already authorized
     * the caller via AuthorizesPetAccess::resolvePetForWrite (tutor OR vet with
     * WRITE/FULL grant) before calling this.
     *
     * @param  array<int, array{file: UploadedFile, type?: string}>  $files
     * @return array<int, ExamImage>
     */
    public function addImages(Exam $exam, array $files, int $uploaderId): array
    {
        $disk = $this->fileUploadService->privateDisk();
        $created = [];

        foreach ($files as $file) {
            /** @var UploadedFile $uploaded */
            $uploaded = $file['file'];

            $path = $this->fileUploadService->uploadForExam($uploaded, $exam->id, $uploaderId);

            $created[] = $exam->images()->create([
                'uploader_id' => $uploaderId,
                'disk' => $disk,
                'file_path' => $path,
                // NUNCA usar o original_name direto no disco — mas guardar como metadado
                // para exibir ao usuário é ok (é escapado no frontend).
                'file_name' => $uploaded->getClientOriginalName(),
                'mime_type' => $uploaded->getMimeType(),
                'file_size' => $uploaded->getSize(),
                'image_type' => $file['type'] ?? null,
            ]);
        }

        return $created;
    }

    public function getPetExams(int $petId)
    {
        return Exam::where('pet_id', $petId)
            ->with(['professional', 'results', 'images'])
            ->orderBy('exam_date', 'desc')
            ->get();
    }

    public function getExamHistory(int $petId, string $parameter): array
    {
        $results = DB::table('exam_results')
            ->join('exams', 'exams.id', '=', 'exam_results.exam_id')
            ->where('exams.pet_id', $petId)
            ->where('exam_results.parameter', $parameter)
            ->where('exams.status', 'completed')
            ->whereNull('exam_results.deleted_at')
            ->whereNull('exams.deleted_at')
            ->select([
                'exam_results.value',
                'exam_results.value_numeric',
                'exam_results.unit',
                'exam_results.reference_min',
                'exam_results.reference_max',
                'exam_results.status',
                'exams.exam_date',
            ])
            ->orderBy('exams.exam_date')
            ->get();

        return [
            'parameter' => $parameter,
            'history' => $results->map(function ($result) {
                return [
                    'date' => $result->exam_date,
                    'value' => $result->value,
                    'value_numeric' => $result->value_numeric !== null ? (float) $result->value_numeric : null,
                    'unit' => $result->unit,
                    'reference_min' => $result->reference_min !== null ? (float) $result->reference_min : null,
                    'reference_max' => $result->reference_max !== null ? (float) $result->reference_max : null,
                    'status' => $result->status,
                ];
            }),
        ];
    }
}
