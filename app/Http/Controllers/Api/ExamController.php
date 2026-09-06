<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesPetAccess;
use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\ExamImage;
use App\Services\Medical\ExamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ExamController extends Controller
{
    use AuthorizesPetAccess;

    public function __construct(
        private readonly ExamService $examService
    ) {}

    public function index(Request $request, int $petId): JsonResponse
    {
        $this->resolvePetForRead($request, $petId);

        $exams = $this->examService->getPetExams($petId);

        return response()->json(['data' => $exams]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pet_id' => 'required|integer|exists:pets,id',
            'exam_type' => 'required|string',
            'exam_name' => 'required|string|max:255',
            'exam_date' => 'required|date',
            'notes' => 'nullable|string',
            'appointment_id' => 'nullable|exists:appointments,id',
        ]);

        $pet = $this->resolvePetForWrite($request, (int) $validated['pet_id']);

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
        $this->resolvePetForWrite($request, (int) $exam->pet_id);

        $this->examService->addResults($exam, $validated['results']);

        return response()->json(['message' => 'Results added successfully']);
    }

    /**
     * POST /exams/{examId}/images
     *
     * Multipart body:
     *   images[0][file]  (required, <=10MB, mime real: pdf|jpg|png|heic|heif)
     *   images[0][type]  (optional, xray|ultrasound|photo|pdf)
     *
     * Returns the created ExamImage rows (id, file_name, mime_type, image_type,
     * file_size, created_at) — NEVER the raw file_path (internal-only).
     */
    public function addImages(Request $request, int $examId): JsonResponse
    {
        $validated = $request->validate([
            'images' => 'required|array|min:1|max:10',
            'images.*.file' => 'required|file|mimes:jpg,jpeg,png,pdf,heic,heif|max:10240',
            'images.*.type' => 'nullable|string|in:xray,ultrasound,photo,pdf',
        ]);

        $exam = Exam::findOrFail($examId);
        $this->resolvePetForWrite($request, (int) $exam->pet_id);

        $created = $this->examService->addImages(
            $exam,
            $validated['images'],
            $request->user()->id
        );

        return response()->json([
            'message' => 'Images uploaded successfully',
            'data' => array_map(fn (ExamImage $img) => $this->toPublicShape($img), $created),
        ], 201);
    }

    /**
     * GET /exams/images/{imageId}/download
     *
     * Two modes:
     *   1. If the configured disk supports temporary URLs (S3/MinIO with signature),
     *      return a short-lived signed URL (10min). Frontend opens it directly.
     *   2. Otherwise (local disk), stream the file through this controller.
     *
     * Authorization: tutor-owner OR vet with active grant (read is enough).
     */
    public function downloadImage(Request $request, int $imageId)
    {
        $image = ExamImage::with('exam')->findOrFail($imageId);

        if (! $image->exam) {
            abort(404, 'Exame não encontrado.');
        }

        // Enforce pet-level authorization via the exam's pet_id.
        $this->resolvePetForRead($request, (int) $image->exam->pet_id);

        $disk = Storage::disk($image->diskName());

        if (! $disk->exists($image->file_path)) {
            Log::warning('ExamController: exam image missing on disk', [
                'image_id' => $image->id,
                'disk' => $image->diskName(),
                'path' => $image->file_path,
            ]);
            abort(404, 'Arquivo não encontrado no armazenamento.');
        }

        // Prefer signed URL when available — escala melhor e não ocupa o worker do PHP.
        try {
            $url = $disk->temporaryUrl($image->file_path, now()->addMinutes(10));

            return response()->json([
                'url' => $url,
                'expires_in' => 600,
                'mime_type' => $image->mime_type,
                'file_name' => $image->file_name,
            ]);
        } catch (\Throwable $e) {
            // Local / non-S3 disks throw — fall back to streaming.
        }

        return $disk->download(
            $image->file_path,
            $image->file_name,
            [
                'Content-Type' => $image->mime_type,
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    /**
     * DELETE /exams/images/{imageId}
     *
     * Somente o tutor-dono do pet pode apagar um anexo (mesma regra aplicada ao
     * `destroy` clínico em PetHealthRecordsController). Vets NÃO podem —
     * prontuário é append-only da perspectiva profissional.
     *
     * Apaga o blob físico e soft-deleta o registro (mantém o rastro no histórico
     * caso seja preciso investigar depois).
     */
    public function destroyImage(Request $request, int $imageId): JsonResponse
    {
        $image = ExamImage::with('exam')->findOrFail($imageId);

        if (! $image->exam) {
            abort(404, 'Exame não encontrado.');
        }

        $pet = \App\Models\Pet::findOrFail($image->exam->pet_id);

        if (! $this->isPetOwner($request->user(), $pet)) {
            abort(403, 'Somente o tutor pode remover anexos de exames.');
        }

        $image->deletePhysicalFile();
        $image->delete(); // soft-delete (registro permanece para auditoria)

        return response()->json(['message' => 'Anexo removido com sucesso.']);
    }

    public function getHistory(Request $request, int $petId, string $parameter): JsonResponse
    {
        $this->resolvePetForRead($request, $petId);

        $history = $this->examService->getExamHistory($petId, $parameter);

        return response()->json(['data' => $history]);
    }

    /**
     * Shape an ExamImage for API responses. Intentionally omits `file_path`
     * (internal-only) and `disk` (implementation detail).
     */
    private function toPublicShape(ExamImage $image): array
    {
        return [
            'id' => $image->id,
            'exam_id' => $image->exam_id,
            'uploader_id' => $image->uploader_id,
            'file_name' => $image->file_name,
            'mime_type' => $image->mime_type,
            'file_size' => $image->file_size,
            'image_type' => $image->image_type,
            'created_at' => $image->created_at,
        ];
    }
}
