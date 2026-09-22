<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\ShowProfessionalTeamRequest;
use App\Http\Resources\ProfessionalTeamMemberResource;
use App\Models\User;
use App\Services\Organization\OrganizationTeamService;
use Illuminate\Http\JsonResponse;

/**
 * Fase 2 do fluxo de agendamento: "se for uma clínica, mostre os profissionais disponíveis
 * na clínica" — resolve o `{id}` (conta que loga como estabelecimento) para a equipe
 * (`organization_members` ativos) que pode ser escolhida para atender.
 */
class TeamController extends Controller
{
    public function __construct(private readonly OrganizationTeamService $teamService) {}

    /** GET public/professionals/{id}/team */
    public function index(ShowProfessionalTeamRequest $request, int $id): JsonResponse
    {
        $establishment = User::find($id);

        if ($establishment === null) {
            return response()->json(['message' => 'Profissional não encontrado.'], 404);
        }

        $organization = $this->teamService->resolveOwnedOrganization($establishment);

        // `id` não é de um estabelecimento com equipe (vet volante, ou organização ainda
        // sem membro algum): não é ERRO, é "não tem equipe" — o app decide sozinho, com
        // `has_team`, se mostra a etapa de escolha de profissional ou pula direto pra agenda.
        if ($organization === null) {
            return response()->json(['data' => [], 'has_team' => false]);
        }

        $members = $this->teamService->bookableMembers($organization, $request->serviceId());

        return response()->json([
            'data' => ProfessionalTeamMemberResource::collection($members),
            'has_team' => true,
            'organization_id' => $organization->id,
        ]);
    }
}
