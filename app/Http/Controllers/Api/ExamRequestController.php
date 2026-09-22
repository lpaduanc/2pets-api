<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesPetAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Exam\StoreExamRequestRequest;
use App\Http\Resources\ExamRequestResource;
use App\Models\ExamRequest;
use App\Services\Medical\ExamRequestService;
use App\Services\Report\ExamRequestPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** `exam-requests` — contrato docs/gap-simplesvet/contratos/16-contrato-api.md. */
class ExamRequestController extends Controller
{
    use AuthorizesPetAccess;

    public function __construct(
        private readonly ExamRequestService $examRequestService,
        private readonly ExamRequestPdfService $pdfService,
    ) {}

    public function store(StoreExamRequestRequest $request): JsonResponse
    {
        $data = $request->validated();
        $pet = $this->resolvePetForWrite($request, (int) $data['pet_id']);

        $examRequest = $this->examRequestService->create($pet, $request->user(), $data);

        return response()->json(['data' => new ExamRequestResource($examRequest)], 201);
    }

    public function pdf(Request $request, ExamRequest $examRequest): Response
    {
        $this->resolvePetForRead($request, (int) $examRequest->pet_id);

        return $this->pdfService->generate($examRequest);
    }
}
