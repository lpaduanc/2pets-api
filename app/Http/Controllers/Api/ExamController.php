<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Services\Medical\ExamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExamController extends Controller
{
    public function __construct(
        private readonly ExamService $examService
    ) {}

    public function index(Request $request, int $petId): JsonResponse
    {
        $exams = $this->examService->getPetExams($petId);

        return response()->json(['data' => $exams]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pet_id' => 'required|exists:pets,id',
            'exam_type' => 'required|string',
            'exam_name' => 'required|string|max:255',
            'exam_date' => 'required|date',
            'notes' => 'nullable|string',
            'appointment_id' => 'nullable|exists:appointments,id',
        ]);

        $pet = \App\Models\Pet::findOrFail($validated['pet_id']);

        $exam = $this->examService->createExam(
            $pet,
            $request->user(),
            $validated['exam_type'],
            $validated['exam_name'],
            \Carbon\Carbon::parse($validated['exam_date']),
            $validated['notes'] ?? null,
            $validated['appointment_id'] ?? null
        );

        return response()->json([
            'message' => 'Exam created successfully',
            'data' => $exam,
        ], 201);
    }

    public function addResults(Request $request, int $examId): JsonResponse
    {
        $validated = $request->validate([
            'results' => 'required|array',
            'results.*.parameter' => 'required|string',
            'results.*.value' => 'required|string',
            'results.*.unit' => 'nullable|string',
            'results.*.reference_range' => 'nullable|string',
            'results.*.status' => 'nullable|in:normal,high,low,critical',
        ]);

        $exam = Exam::findOrFail($examId);

        $this->examService->addResults($exam, $validated['results']);

        return response()->json(['message' => 'Results added successfully']);
    }

    public function addImages(Request $request, int $examId): JsonResponse
    {
        $validated = $request->validate([
            'images' => 'required|array',
            'images.*.file' => 'required|file|mimes:jpg,jpeg,png,pdf|max:10240',
            'images.*.type' => 'nullable|string|in:xray,ultrasound,photo,pdf',
        ]);

        $exam = Exam::findOrFail($examId);

        $this->examService->addImages(
            $exam,
            $validated['images'],
            $request->user()->id
        );

        return response()->json(['message' => 'Images uploaded successfully']);
    }

    public function getHistory(Request $request, int $petId, string $parameter): JsonResponse
    {
        $history = $this->examService->getExamHistory($petId, $parameter);

        return response()->json(['data' => $history]);
    }
}

