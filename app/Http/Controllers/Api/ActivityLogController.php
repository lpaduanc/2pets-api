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
use App\Models\User;
use App\Models\Vaccination;
use App\Services\Audit\ActivityFeedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET activity-log/{resource}/{id}` — feed de auditoria genérico por registro, item 22 do
 * backlog gap-simplesvet. `PetAuditController` (`pets/{pet}/audit`) continua existindo como
 * rota própria por compatibilidade; este endpoint é o ponto de extensão para os demais
 * recursos (venda, produto, regra de comissão) conforme cada agente de domínio decidir a
 * própria regra de visibilidade — ver `self::RESOURCE_MAP`.
 *
 * Alocado hoje só para `pets` (mesma visibilidade de `PetAuditController`, via
 * `AuthorizesPetAccess::resolvePetAuditVisibility()` compartilhado). Adicionar um recurso
 * novo é: 1) uma entrada em `RESOURCE_MAP`; 2) um `case` em `resolveVisibility()` decidindo
 * quem pode ler e se a leitura é irrestrita ou só as próprias entradas.
 */
class ActivityLogController extends Controller
{
    use AuthorizesPetAccess;

    /**
     * resource-slug => [classe do model, FK usada pelos filhos, classes filhas].
     *
     * @var array<string, array{0: class-string, 1: string, 2: list<class-string>}>
     */
    private const RESOURCE_MAP = [
        'pets' => [Pet::class, 'pet_id', [
            Vaccination::class, PetDeworming::class, PetMedication::class,
            Surgery::class, Exam::class, Hospitalization::class,
        ]],
    ];

    public function __construct(private readonly ActivityFeedService $activityFeedService) {}

    public function index(Request $request, string $resource, int $id): JsonResponse
    {
        if (! isset(self::RESOURCE_MAP[$resource])) {
            abort(404, "Auditoria genérica ainda não está disponível para \"{$resource}\".");
        }

        [$modelClass, $foreignKey, $childClasses] = self::RESOURCE_MAP[$resource];
        $subject = $modelClass::findOrFail($id);
        $restrictToCauserId = $this->resolveVisibility($resource, $request->user(), $subject);

        $perPage = max(1, min(100, (int) $request->input('per_page', 25)));
        $childSubjectIds = $this->activityFeedService->collectChildSubjectIds($childClasses, $foreignKey, $id);
        $page = $this->activityFeedService->paginate($modelClass, $id, $childSubjectIds, $restrictToCauserId, $perPage);

        return response()->json($page);
    }

    /**
     * @return int|null null = vê tudo; int = restringe às entradas causadas por este usuário.
     */
    private function resolveVisibility(string $resource, ?User $user, mixed $subject): ?int
    {
        return match ($resource) {
            'pets' => $this->resolvePetAuditVisibility($user, $subject),
            default => abort(404),
        };
    }
}
