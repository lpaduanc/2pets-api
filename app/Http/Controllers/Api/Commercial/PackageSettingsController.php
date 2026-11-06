<?php

namespace App\Http\Controllers\Api\Commercial;

use App\Enums\ExpiredPackagePolicy;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Commercial\CommercialScopeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * `GET/PUT package-settings` (PLANO 2.7): o que fazer com o saldo de pacote vencido
 * (`client_credit` padrão | `expiration_revenue`) e quantos dias antes do vencimento o pacote
 * aparece como "À vencer". Da organização principal de quem chama; vet volante sem organização
 * fica no padrão (crédito ao cliente, 30 dias).
 */
class PackageSettingsController extends Controller
{
    public function __construct(private readonly CommercialScopeResolver $scope) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present($this->organizationFor($request))]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'expired_package_policy' => ['sometimes', Rule::enum(ExpiredPackagePolicy::class)],
            'package_expiring_soon_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
        ]);
        $organization = $this->organizationFor($request);
        $organization->update($data);

        return response()->json(['data' => $this->present($organization->fresh()), 'message' => 'Configuração de pacotes atualizada.']);
    }

    private function organizationFor(Request $request): Organization
    {
        $organizationId = $this->scope->primaryOrganizationId($request->user());
        abort_if($organizationId === null, 404, 'Profissional sem organização usa a configuração padrão de pacotes.');

        return Organization::findOrFail($organizationId);
    }

    /** @return array<string, mixed> */
    private function present(Organization $organization): array
    {
        $policy = $organization->expired_package_policy ?? ExpiredPackagePolicy::CLIENT_CREDIT;

        return [
            'expired_package_policy' => $policy->value,
            'expired_package_policy_label' => $policy->label(),
            'expired_package_policy_options' => array_map(fn (ExpiredPackagePolicy $p): array => ['value' => $p->value, 'label' => $p->label()], ExpiredPackagePolicy::cases()),
            'package_expiring_soon_days' => $organization->package_expiring_soon_days ?? 30,
        ];
    }
}
