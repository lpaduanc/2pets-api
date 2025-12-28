<?php

namespace App\Services\Medical;

use App\Models\Exam;
use App\Models\Pet;
use App\Models\User;
use App\Services\FileUploadService;
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

        if (!$exam->isCompleted()) {
            $exam->complete();
        }

        // TODO: Notify pet owner about results
    }

    public function addImages(Exam $exam, array $files, int $userId): void
    {
        foreach ($files as $file) {
            $path = $this->fileUploadService->uploadFile(
                $file['file'],
                'exams',
                $userId
            );

            $exam->images()->create([
                'file_path' => $path,
                'file_name' => $file['file']->getClientOriginalName(),
                'mime_type' => $file['file']->getMimeType(),
                'file_size' => $file['file']->getSize(),
                'image_type' => $file['type'] ?? null,
            ]);
        }
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

