<?php

namespace App\Http\Controllers\Api\Fiscal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Fiscal\UpdateFiscalSettingsRequest;
use App\Models\FiscalDocument;
use App\Models\Organization;
use App\Services\Commercial\CommercialScopeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Regime/inscrições fiscais da organização emitente — contrato
 * docs/gap-simplesvet/05-emissao-fiscal-nfe-nfce-nfse-spec.md. Reusa
 * `FiscalDocumentPolicy::create()` (mesma régua "só OWNER") — não crio uma policy nova só para
 * um recurso sem model de agregado próprio.
 *
 * Certificado A1 não tem endpoint aqui: `certificate_ref`/`certificate_expires_at` são
 * escritos pelo fluxo do cofre externo (`security-specialist`), nunca por este controller.
 */
class FiscalSettingsController extends Controller
{
    public function __construct(private readonly CommercialScopeResolver $scope) {}

    public function show(Request $request): JsonResponse
    {
        $organization = $this->organizationFor($request);

        return response()->json(['data' => $this->toArray($organization)]);
    }

    public function update(UpdateFiscalSettingsRequest $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('create', FiscalDocument::class);

        $organization = $this->organizationFor($request);
        $organization->update($request->validated());

        return response()->json(['data' => $this->toArray($organization->fresh()), 'message' => 'Dados fiscais atualizados.']);
    }

    private function organizationFor(Request $request): Organization
    {
        $organizationId = $this->scope->primaryOrganizationId($request->user());
        abort_if($organizationId === null, 404, 'Profissional sem organização não tem configuração fiscal de organização.');

        return Organization::findOrFail($organizationId);
    }

    /** @return array<string, mixed> */
    private function toArray(Organization $organization): array
    {
        return [
            'tax_regime' => $organization->tax_regime?->value,
            'tax_regime_label' => $organization->tax_regime?->label(),
            'municipal_registration' => $organization->municipal_registration,
            'state_registration' => $organization->state_registration,
            'state_registration_type' => $organization->state_registration_type?->value,
            'cnae_code' => $organization->cnae_code,
            'special_tax_regime' => $organization->special_tax_regime,
            'iss_rate' => $organization->iss_rate === null ? null : (float) $organization->iss_rate,
            'certificate_expires_at' => $organization->certificate_expires_at?->toDateString(),
            'has_certificate' => $organization->certificate_ref !== null,
        ];
    }
}
