<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesPetAccess;
use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Models\Pet;
use App\Services\Medical\PetTimelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /pets/{pet}/timeline — item 11 do MVP. Tutor + vet com `PetVetAccess` (leitura).
 */
class PetTimelineController extends Controller
{
    use AuthorizesPetAccess, PaginatesResults;

    private const DEFAULT_PER_PAGE = 20;

    public function __construct(
        private readonly PetTimelineService $timelineService,
    ) {}

    public function __invoke(Request $request, int $pet): JsonResponse
    {
        $petModel = $this->resolvePetForRead($request, $pet);
        $page = max((int) $request->input('page', 1), 1);
        $perPage = $this->resolvePerPage($request, self::DEFAULT_PER_PAGE);

        $timeline = $this->timelineService->forPet($petModel, $page, $perPage);

        return response()->json([
            'data' => $timeline['items'],
            'meta' => $this->meta($timeline),
            'pathologies' => $this->pathologiesSnapshot($petModel),
        ]);
    }

    /**
     * @param  array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}  $timeline
     * @return array{current_page: int, per_page: int, total: int, last_page: int}
     */
    private function meta(array $timeline): array
    {
        return [
            'current_page' => $timeline['page'],
            'per_page' => $timeline['per_page'],
            'total' => $timeline['total'],
            'last_page' => (int) max(1, ceil($timeline['total'] / $timeline['per_page'])),
        ];
    }

    /**
     * Snapshot ATUAL do pet, não um evento datado — por isso fora do stream paginado da
     * timeline (ver `PetTimelineService`). `chronic_diseases` e `chronic_conditions`
     * coexistem no model `Pet` hoje; mesclar os dois evita esconder dado gravado no campo
     * legado.
     *
     * @return list<string>
     */
    private function pathologiesSnapshot(Pet $pet): array
    {
        return collect($pet->chronic_diseases ?? [])
            ->merge($pet->chronic_conditions ?? [])
            ->filter(fn (mixed $condition): bool => is_string($condition) && $condition !== '')
            ->unique()
            ->values()
            ->all();
    }
}
