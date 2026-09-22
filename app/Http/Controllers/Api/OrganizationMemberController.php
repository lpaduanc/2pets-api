<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrganizationRole;
use App\Http\Controllers\Concerns\ResolvesOrganizationChildren;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\UpdateOrganizationMemberPermissionsRequest;
use App\Http\Requests\Organization\UpdateOrganizationMemberRequest;
use App\Http\Resources\OrganizationMemberResource;
use App\Models\Organization;
use App\Services\Organization\OrganizationMemberPermissionOverrideService;
use App\Services\Organization\OrganizationMemberService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrganizationMemberController extends Controller
{
    use AuthorizesRequests, ResolvesOrganizationChildren;

    public function __construct(
        private readonly OrganizationMemberService $memberService,
        private readonly OrganizationMemberPermissionOverrideService $permissionOverrideService,
    ) {}

    public function index(Organization $organization): AnonymousResourceCollection
    {
        $this->authorize('manageMembers', $organization);

        return OrganizationMemberResource::collection($this->memberService->listMembers($organization));
    }

    public function update(UpdateOrganizationMemberRequest $request, Organization $organization, int $member): OrganizationMemberResource
    {
        $organizationMember = $this->resolveMember($organization, $member);

        $role = $request->has('role') ? OrganizationRole::from($request->validated('role')) : null;
        $isActive = $request->has('is_active') ? $request->boolean('is_active') : null;

        $updated = $this->memberService->updateMember($organizationMember, $role, $isActive);

        return new OrganizationMemberResource($updated->load(['user.professional', 'serviceAreas']));
    }

    /**
     * `PUT organizations/{organization}/members/{member}/permissions` — item 22 do backlog
     * gap-simplesvet. Só dono; blindagem contra ato clínico em
     * `UpdateOrganizationMemberPermissionsRequest` + `OrganizationMember::hasPermission()`.
     */
    public function updatePermissions(UpdateOrganizationMemberPermissionsRequest $request, Organization $organization, int $member): OrganizationMemberResource
    {
        $organizationMember = $this->resolveMember($organization, $member);
        $updated = $this->permissionOverrideService->replace($organizationMember, $request->validated('permissions'));

        return new OrganizationMemberResource($updated->load(['user.professional', 'serviceAreas']));
    }

    public function destroy(Organization $organization, int $member): JsonResponse
    {
        $this->authorize('manageMembers', $organization);

        $this->memberService->deactivate($this->resolveMember($organization, $member));

        return response()->json(null, 204);
    }
}
