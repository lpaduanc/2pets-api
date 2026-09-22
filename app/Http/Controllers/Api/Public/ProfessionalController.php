<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProfessionalSearchResource;
use App\Http\Resources\PublicProfessionalSearchResource;
use App\Models\User;
use App\Services\Organization\OrganizationServiceCatalog;
use App\Services\Organization\OrganizationTeamService;
use App\Services\Organization\TeamSizeQuery;
use Illuminate\Http\JsonResponse;

class ProfessionalController extends Controller
{
    public function __construct(
        private readonly OrganizationTeamService $teamService,
        private readonly OrganizationServiceCatalog $serviceCatalog,
    ) {}

    public function show(string $id): JsonResponse
    {
        $query = User::where('id', $id)
            ->visibleProfessional()
            ->with(['professional', 'professional.services']);

        TeamSizeQuery::applyTo($query);

        $professional = $query->first();

        if (! $professional) {
            return response()->json([
                'message' => 'Professional not found',
            ], 404);
        }

        return response()->json([
            'data' => $this->resourceFor($professional),
            // Item 3 da Fase 2: união dos serviços da equipe, agrupada — só quando o
            // estabelecimento TEM equipe (senão o array de serviços do resource já basta).
            'team_services' => $this->teamServicesFor($professional),
        ]);
    }

    /**
     * @return list<array{name: string, category: string, duration_minutes: int, price_min: float, price_max: float, professional_ids: list<int>}>
     */
    private function teamServicesFor(User $professional): array
    {
        $organization = $this->teamService->resolveOwnedOrganization($professional);

        return $organization === null ? [] : $this->serviceCatalog->catalogFor($organization);
    }

    /**
     * The full profile page is public by design (share links, SEO, the "veja o perfil
     * completo" conversion hook from CLAUDE.md) — this route has no `auth:sanctum`
     * middleware, so an anonymous visitor must still get a 200. Contact (email/phone) is
     * what has to stay login-gated, matching the search cards (`PublicProfessionalSearchResource`)
     * and the frontend's own `isAuthenticated` gate on booking/messaging for this same page.
     *
     * `auth('sanctum')->user()` resolves the bearer token directly against the guard and
     * returns null silently when absent (`Laravel\Sanctum\Guard`) — it never throws, so it's
     * safe to call outside an `auth:sanctum` middleware group.
     */
    private function resourceFor(User $professional): ProfessionalSearchResource
    {
        return auth('sanctum')->user() !== null
            ? new ProfessionalSearchResource($professional)
            : new PublicProfessionalSearchResource($professional);
    }
}
