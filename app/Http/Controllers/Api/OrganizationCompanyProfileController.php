<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\UpdateOrganizationCompanyProfileRequest;
use App\Http\Resources\OrganizationCompanyProfileResource;
use App\Models\Organization;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Fiscal\Transmission\FiscalTransmissionReadiness;
use App\Services\Organization\OrganizationCnpjChangeGuard;
use App\Services\Organization\OrganizationCompanyProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * `professional/organization` — razão social, CNPJ e endereço da organização principal do
 * usuário, editáveis pelo dono. Até aqui só o cadastro gravava esses campos, e a transmissão
 * própria (spec docs/gap-petflow/specs/fase4-transmissao-propria-nfe-nfce.md §6) os cobra em
 * `transmission.missing` sem a empresa ter onde corrigi-los.
 */
class OrganizationCompanyProfileController extends Controller
{
    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly OrganizationCompanyProfileService $profiles,
        private readonly OrganizationCnpjChangeGuard $cnpjGuard,
        private readonly FiscalTransmissionReadiness $readiness,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->resource($this->organizationFor($request))]);
    }

    public function update(UpdateOrganizationCompanyProfileRequest $request): JsonResponse
    {
        $organization = $this->profiles->update($request->organization(), $request->validated(), $request->user());

        return response()->json(['data' => $this->resource($organization), 'message' => 'Dados da empresa atualizados.']);
    }

    private function organizationFor(Request $request): Organization
    {
        $organizationId = $this->scope->primaryOrganizationId($request->user());
        abort_if($organizationId === null, 404, 'Profissional sem organização não tem dados de empresa para editar.');
        $organization = Organization::findOrFail($organizationId);
        Gate::forUser($request->user())->authorize('manageCompanyProfile', $organization);

        return $organization;
    }

    private function resource(Organization $organization): OrganizationCompanyProfileResource
    {
        return new OrganizationCompanyProfileResource(
            $organization,
            $this->readiness->emitterCityCode($organization),
            $this->cnpjGuard->hasIssuedDocuments($organization),
        );
    }
}
