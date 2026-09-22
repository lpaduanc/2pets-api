<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesOrganizationChildren;
use App\Http\Controllers\Controller;
use App\Http\Requests\ServiceArea\StoreServiceAreaRequest;
use App\Http\Requests\ServiceArea\SyncMemberServiceAreasRequest;
use App\Http\Resources\ServiceAreaResource;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Áreas de atendimento da organização (Clínica, Banho e Tosa, Cirurgia...) — item 21 do
 * backlog gap-simplesvet. Escopo: só dentro da própria organização da URL (mesma defesa de
 * IDOR de `ResolvesOrganizationChildren`).
 */
class ServiceAreaController extends Controller
{
    use ResolvesOrganizationChildren;

    public function index(Organization $organization): AnonymousResourceCollection
    {
        return ServiceAreaResource::collection(
            $organization->serviceAreas()->orderBy('name')->get()
        );
    }

    public function store(StoreServiceAreaRequest $request, Organization $organization): JsonResponse
    {
        $area = $organization->serviceAreas()->create($request->validated());

        return (new ServiceAreaResource($area))->response()->setStatusCode(201);
    }

    public function update(StoreServiceAreaRequest $request, Organization $organization, int $id): ServiceAreaResource
    {
        $area = $organization->serviceAreas()->findOrFail($id);
        $area->update($request->validated());

        return new ServiceAreaResource($area);
    }

    public function destroy(Organization $organization, int $id): JsonResponse
    {
        $organization->serviceAreas()->findOrFail($id)->delete();

        return response()->json(['message' => 'Área de atendimento removida.']);
    }

    /** `PUT organizations/{organization}/members/{member}/service-areas` — sync. */
    public function syncMemberAreas(SyncMemberServiceAreasRequest $request, Organization $organization, int $member): AnonymousResourceCollection
    {
        $organizationMember = $this->resolveMember($organization, $member);
        $organizationMember->serviceAreas()->sync($request->validated('service_area_ids'));

        return ServiceAreaResource::collection($organizationMember->serviceAreas()->get());
    }
}
