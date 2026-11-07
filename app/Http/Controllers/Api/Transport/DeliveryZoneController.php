<?php

namespace App\Http\Controllers\Api\Transport;

use App\Http\Controllers\Controller;
use App\Http\Requests\Transport\StoreDeliveryZoneRequest;
use App\Models\DeliveryZone;
use App\Models\Organization;
use App\Models\OrganizationMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Zonas do leva e traz da organização (PLANO 3.7), no padrão de `ServiceAreaController`. Escopo:
 * só a organização da URL, e só para MEMBRO dela (as permissões de rota são por papel, não por
 * organização — sem esta checagem o gestor de uma clínica mexeria nas zonas de outra). Leitura
 * para quem vê a agenda; escrita com `service-areas.manage` (rotas).
 *
 * Excluir zona já usada em transporte DESATIVA (`active=false`) em vez de apagar: o transporte
 * guarda a zona que cobrou, e a lista do dia/os relatórios continuam mostrando o nome. Zona nunca
 * usada é apagada de verdade.
 */
class DeliveryZoneController extends Controller
{
    public function index(Request $request, Organization $organization): JsonResponse
    {
        $this->assertMember($request, $organization);
        $zones = DeliveryZone::where('organization_id', $organization->id)->orderBy('name')->get();

        return response()->json(['data' => $zones->map(fn (DeliveryZone $z): array => self::present($z))->values()]);
    }

    public function store(StoreDeliveryZoneRequest $request, Organization $organization): JsonResponse
    {
        $this->assertMember($request, $organization);
        $zone = DeliveryZone::create($request->zoneAttributes() + ['organization_id' => $organization->id, 'professional_id' => $request->user()->id]);

        return response()->json(['data' => self::present($zone)], 201);
    }

    public function update(StoreDeliveryZoneRequest $request, Organization $organization, int $id): JsonResponse
    {
        $this->assertMember($request, $organization);
        $zone = DeliveryZone::where('organization_id', $organization->id)->findOrFail($id);
        $zone->update($request->zoneAttributes());

        return response()->json(['data' => self::present($zone->fresh())]);
    }

    public function destroy(Request $request, Organization $organization, int $id): JsonResponse
    {
        $this->assertMember($request, $organization);
        $zone = DeliveryZone::where('organization_id', $organization->id)->findOrFail($id);

        if ($zone->transports()->exists()) {
            $zone->update(['active' => false]);

            return response()->json(['message' => 'Zona já usada em transporte: foi desativada, não apagada.', 'data' => self::present($zone->fresh())]);
        }

        $zone->delete();

        return response()->json(['message' => 'Zona removida.']);
    }

    private function assertMember(Request $request, Organization $organization): void
    {
        abort_unless(OrganizationMember::where('organization_id', $organization->id)->where('user_id', $request->user()->id)->where('is_active', true)->exists(), 404);
    }

    /** @return array<string, mixed> */
    public static function present(DeliveryZone $zone): array
    {
        return [
            'id' => $zone->id,
            'organization_id' => $zone->organization_id,
            'name' => $zone->name,
            'rule_type' => $zone->rule_type,
            'ceps' => $zone->ceps ?? [],
            'max_km' => $zone->max_km === null ? null : (string) $zone->max_km,
            'fee' => (string) $zone->fee,
            'active' => (bool) $zone->active,
        ];
    }
}
