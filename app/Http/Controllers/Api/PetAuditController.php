<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesPetAccess;
use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\Hospitalization;
use App\Models\Pet;
use App\Models\PetDeworming;
use App\Models\PetMedication;
use App\Models\Surgery;
use App\Models\Vaccination;
use App\Services\Audit\ActivityFeedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /pets/{pet}/audit — chronological activity feed for a pet and its clinical records.
 *
 * Visibility matrix:
 *   - Pet owner (tutor)  → every change ever made to the pet and its health records.
 *   - Admin              → same as owner.
 *   - Vet with active grant → only the entries where `causer_id = their own user_id`.
 *   - Anyone else        → 403.
 *
 * A consulta/formatação do `activity_log` (tabela polimórfica única do spatie/
 * laravel-activitylog) mora em `ActivityFeedService` (item 22 do backlog gap-simplesvet) —
 * este controller só resolve a matriz de visibilidade específica de pet, que não é genérica
 * o bastante para virar parte do serviço compartilhado.
 */
class PetAuditController extends Controller
{
    use AuthorizesPetAccess;

    /**
     * Every model class whose activity logs should surface in the pet's timeline.
     * Kept as a constant so adding a new clinical resource is a one-liner.
     */
    private const CHILD_SUBJECT_CLASSES = [
        Vaccination::class,
        PetDeworming::class,
        PetMedication::class,
        Surgery::class,
        Exam::class,
        Hospitalization::class,
    ];

    public function __construct(private readonly ActivityFeedService $activityFeedService) {}

    public function index(Request $request, int $petId): JsonResponse
    {
        $pet = Pet::findOrFail($petId);
        $restrictToCauserId = $this->resolvePetAuditVisibility($request->user(), $pet);

        $perPage = max(1, min(100, (int) $request->input('per_page', 25)));
        $childSubjectIds = $this->activityFeedService->collectChildSubjectIds(self::CHILD_SUBJECT_CLASSES, 'pet_id', $petId);

        $page = $this->activityFeedService->paginate(Pet::class, $pet->id, $childSubjectIds, $restrictToCauserId, $perPage);

        return response()->json($page);
    }
}
