<?php

namespace App\Services\Medical;

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

    public function addResults(Exam $exam, array $results): void
    {
        foreach ($results as $result) {
            $exam->results()->create([
                'parameter' => $result['parameter'],
                'value' => $result['value'],
                'unit' => $result['unit'] ?? null,
                'reference_range' => $result['reference_range'] ?? null,
                'status' => $result['status'] ?? null,
            ]);
        }

        if (! $exam->isCompleted()) {
            $exam->complete();
        }

        // TODO: Notify pet owner about results
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
            ->select([
                'exam_results.value',
                'exam_results.unit',
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
                    'unit' => $result->unit,
                    'status' => $result->status,
                ];
            }),
        ];
    }
}
