<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesPetAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Exam\AddExamResultsRequest;
use App\Http\Requests\Exam\FinalizeExamRequest;
use App\Http\Requests\Exam\StoreExamRequest;
use App\Models\Exam;
use App\Models\ExamImage;
use App\Services\Hospitalization\HospitalizationExamService;
use App\Services\Medical\ExamReportService;
use App\Services\Medical\ExamService;
use App\Services\Report\ExamReportPdfService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ExamController extends Controller
{
    use AuthorizesPetAccess;

    public function __construct(
        private readonly ExamService $examService,
        private readonly HospitalizationExamService $hospitalizationExamService,
        private readonly ExamReportService $reportService,
        private readonly ExamReportPdfService $pdfService,
    ) {}

    public function index(Request $request, int $petId): JsonResponse
    {
        $this->resolvePetForRead($request, $petId);

        $exams = $this->examService->getPetExams($petId);

        return response()->json(['data' => $exams]);
    }

    /**
     * Pet com internação ativa (contrato docs/atendimento-veterinario/
     * 11-internacao-no-fluxo-de-faturamento.md §2.2): o exame entra na conta da própria
     * internação em vez de aceitar `appointment_id` do payload — decisão do
     * `HospitalizationExamService`, não deste controller.
     */
    public function store(StoreExamRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $pet = $this->resolvePetForWrite($request, (int) $validated['pet_id']);

        $exam = $this->hospitalizationExamService->createExam($pet, $request->user(), [
            'exam_type' => $validated['exam_type'],
            'exam_name' => $validated['exam_name'],
            'exam_type_id' => $validated['exam_type_id'] ?? null,
            'exam_date' => Carbon::parse($validated['exam_date']),
            'notes' => $validated['notes'] ?? null,
            'appointment_id' => $validated['appointment_id'] ?? null,
            'service_id' => $validated['service_id'] ?? null,
            'unit_price' => $validated['unit_price'] ?? null,
        ]);

        return response()->json([
            'message' => 'Exam created successfully',
            'data' => $exam,
        ], 201);
    }

    /** `POST /exams/{id}/finalize` — contrato docs/gap-simplesvet/contratos/16-contrato-api.md. */
    public function finalize(FinalizeExamRequest $request, int $examId): JsonResponse
    {
        $exam = Exam::findOrFail($examId);
        $this->resolvePetForWrite($request, (int) $exam->pet_id);

        $exam = $this->reportService->finalize($exam, $request->validated());

        return response()->json(['data' => $exam]);
    }

    /** `GET /exams/{id}/pdf` — leitura compartilhada tutor + vet autorizado. */
    public function pdf(Request $request, int $examId)
    {
        $exam = Exam::findOrFail($examId);
        $this->resolvePetForRead($request, (int) $exam->pet_id);

        return $this->pdfService->generate($exam);
    }

    public function addResults(AddExamResultsRequest $request, int $examId): JsonResponse
    {
        $exam = Exam::findOrFail($examId);
        $this->resolvePetForWrite($request, (int) $exam->pet_id);

        $this->examService->addResults($exam, $request->validated('results'));

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
