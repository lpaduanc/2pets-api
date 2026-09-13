<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrganizationRole;
use App\Http\Controllers\Concerns\ResolvesOrganizationChildren;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\InviteOrganizationMemberRequest;
use App\Http\Resources\OrganizationInvitationAcceptedResource;
use App\Http\Resources\OrganizationInvitationResource;
use App\Http\Resources\PublicOrganizationInvitationResource;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Services\Organization\OrganizationInvitationService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrganizationInvitationController extends Controller
{
    use AuthorizesRequests, ResolvesOrganizationChildren;

    public function __construct(
        private readonly OrganizationInvitationService $invitationService,
    ) {}

    public function store(InviteOrganizationMemberRequest $request, Organization $organization): JsonResponse
    {
        $invitation = $this->invitationService->invite(
            $organization,
            $request->user(),
            $request->validated('email'),
            OrganizationRole::from($request->validated('role')),
        );

        return (new OrganizationInvitationResource($invitation))
            ->response()
            ->setStatusCode(201);
    }

    public function index(Organization $organization): AnonymousResourceCollection
    {
        $this->authorize('manageMembers', $organization);

        return OrganizationInvitationResource::collection($organization->invitations()->pending()->get());
    }

    public function destroy(Organization $organization, int $invitation): JsonResponse
    {
        $this->authorize('manageMembers', $organization);

        $this->invitationService->revoke($this->resolveInvitation($organization, $invitation));

        return response()->json(null, 204);
    }

    /** Público — sem auth. Só o necessário pra tela de aceite decidir o que mostrar. */
    public function show(string $token): PublicOrganizationInvitationResource
    {
        $invitation = OrganizationInvitation::query()
            ->with('organization')
            ->where('token', $token)
            ->firstOrFail();

        return new PublicOrganizationInvitationResource($invitation);
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        $invitation = OrganizationInvitation::query()->where('token', $token)->firstOrFail();

        $member = $this->invitationService->accept($invitation, $request->user());

        // `OrganizationMember::updateOrCreate()` deixa `wasRecentlyCreated` ligado quando cria
        // a linha — sem forçar 200 aqui, o primeiro aceite responderia 201 e um reenvio
        // idempotente responderia 200, dois códigos diferentes pro mesmo contrato de rota.
        return (new OrganizationInvitationAcceptedResource($member))
            ->response()
            ->setStatusCode(200);
    }
}
