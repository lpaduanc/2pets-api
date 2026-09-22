<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Exam\StoreExamTypeRequest;
use App\Http\Requests\Exam\UpdateExamTypeRequest;
use App\Http\Resources\ExamTypeResource;
use App\Models\ExamType;
use App\Services\Medical\ExamTypeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/** `exam-types` — contrato docs/gap-simplesvet/contratos/16-contrato-api.md. */
class ExamTypeController extends Controller
{
    public function __construct(private readonly ExamTypeService $examTypeService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $examTypes = ExamType::query()
            ->visibleTo($request->user()->activeOrganizationId())
            ->when($request->query('category'), fn ($query, $category) => $query->where('category', $category))
            ->orderBy('name')
            ->get();

        return ExamTypeResource::collection($examTypes);
    }

    public function store(StoreExamTypeRequest $request): JsonResponse
    {
        $organizationId = $request->user()->activeOrganizationId();
        abort_if($organizationId === null, 422, 'É preciso ter uma organização ativa para cadastrar um tipo de exame.');

        $examType = $this->examTypeService->create($request->validated(), $organizationId);

        return response()->json(['data' => new ExamTypeResource($examType)], 201);
    }

    public function show(Request $request, ExamType $examType): ExamTypeResource
    {
        Gate::forUser($request->user())->authorize('view', $examType);

        return new ExamTypeResource($examType);
    }

    public function update(UpdateExamTypeRequest $request, ExamType $examType): JsonResponse
    {
        Gate::forUser($request->user())->authorize('update', $examType);

        $examType = $this->examTypeService->update($examType, $request->validated());

        return response()->json(['data' => new ExamTypeResource($examType)]);
    }

    public function destroy(Request $request, ExamType $examType): JsonResponse
    {
        Gate::forUser($request->user())->authorize('delete', $examType);

        $examType->delete();

        return response()->json(['message' => 'Tipo de exame removido com sucesso.']);
    }
}
