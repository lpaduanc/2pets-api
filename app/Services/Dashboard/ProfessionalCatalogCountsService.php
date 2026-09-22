<?php

namespace App\Services\Dashboard;

use App\DataTransferObjects\ProfessionalDashboardScope;
use App\Models\MedicalRecord;
use App\Models\Product;
use App\Models\Service;

/**
 * Contadores de catálogo do dashboard do profissional (item 1 da auditoria): prontuários,
 * serviços ativos e estoque. Antes desses três, o controller nunca calculava nada — o front
 * sempre recebia (ou assumia) `0`, que um profissional lê de manhã como "nada crítico" quando
 * na verdade era "não implementado".
 *
 * Estoque lê `Product` (`controls_stock=true`) desde a consolidação — antes lia `Inventory`,
 * que só cobria o insumo clínico; agora o contador reflete o catálogo inteiro do profissional
 * (docs/gap-simplesvet/specs/produtos-estoque-consolidado-spec.md).
 */
final class ProfessionalCatalogCountsService
{
    /**
     * Mesmo horizonte de "vencendo em breve" já usado no projeto para lembrete de vacina/vermífugo
     * (`HealthEventLevel::DUE_SOON_THRESHOLD_DAYS`, `SendHealthReminders`) — mantém a mesma
     * semântica de "30 dias" em vez de inventar um novo número mágico só para estoque.
     */
    private const EXPIRY_WARNING_DAYS = 30;

    /**
     * @return array{totalRecords: ?int, activeServices: int, lowStockItems: int, expiringSoonItems: int}
     */
    public function collect(ProfessionalDashboardScope $scope): array
    {
        $inventoryCounters = $this->fetchInventoryCounters($scope->professionalIds);

        return [
            // Prontuário é ato clínico — petshop puro não tem prontuário para contar
            // (CLAUDE.md: petshop não presta consulta veterinária). `null` é "não se aplica",
            // diferente de `0` ("aplica e está zerado").
            'totalRecords' => $scope->isClinical ? $this->countMedicalRecords($scope->professionalIds) : null,
            'activeServices' => $this->countActiveServices($scope->professionalIds),
            'lowStockItems' => $inventoryCounters['low_stock'],
            'expiringSoonItems' => $inventoryCounters['expiring_soon'],
        ];
    }

    /**
     * @param  list<int>  $professionalIds
     */
    private function countMedicalRecords(array $professionalIds): int
    {
        return MedicalRecord::whereIn('professional_id', $professionalIds)->count();
    }

    /**
     * @param  list<int>  $professionalIds
     */
    private function countActiveServices(array $professionalIds): int
    {
        return Service::whereIn('professional_id', $professionalIds)
            ->where('active', true)
            ->count();
    }

    /**
     * One query for both counters instead of two — same `COUNT(*) FILTER` pattern already
     * established for this dashboard (Fase 8).
     *
     * @param  list<int>  $professionalIds
     * @return array{low_stock: int, expiring_soon: int}
     */
    private function fetchInventoryCounters(array $professionalIds): array
    {
        $row = Product::whereIn('professional_id', $professionalIds)
            ->where('controls_stock', true)
            ->selectRaw(
                'COUNT(*) FILTER (WHERE stock_quantity <= min_stock) AS low_stock,
                 COUNT(*) FILTER (WHERE expiry_date IS NOT NULL AND expiry_date <= ?) AS expiring_soon',
                [now()->addDays(self::EXPIRY_WARNING_DAYS)]
            )
            ->first();

        return [
            'low_stock' => (int) $row->low_stock,
            'expiring_soon' => (int) $row->expiring_soon,
        ];
    }
}
