<?php

use App\Services\Stock\InventoryProductConsolidationService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Migração de dado (não de schema) — consolida `inventories`/`inventory_movements` dentro de
 * `products`/`product_batches`/`stock_movements`. Contrato completo em
 * docs/gap-simplesvet/specs/produtos-estoque-consolidado-spec.md e
 * docs/gap-simplesvet/contratos/produtos-estoque-consolidado-contrato-api.md.
 *
 * Não apaga nem edita `inventories`/`inventory_movements` — os dois ficam como legado
 * histórico (`@deprecated`), lidos só por rastreabilidade (`legacy_inventory_id`).
 *
 * Idempotente: reexecutar (`php artisan migrate` numa base onde esta migration já rodou) só
 * migra o que ainda não tem `legacy_inventory_id` — ver
 * `InventoryProductConsolidationService::alreadyMigrated()`.
 */
return new class extends Migration
{
    /**
     * Cada grupo migra na própria transação (`InventoryProductConsolidationService::
     * migrateGroup()`); uma transação única cobrindo a migration inteira reteria lock de linha
     * em `products`/`suppliers`/`product_groups` pelo tempo total da migração — mesmo cuidado
     * já adotado em `2026_09_15_120000_rerun_commercial_organization_backfill`.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        $summary = app(InventoryProductConsolidationService::class)->migrate();

        Log::info('Consolidação de estoque: inventories → products', $summary);
    }

    public function down(): void
    {
        // Intencionalmente no-op: os produtos/lotes/movimentos migrados são dado real da
        // clínica a partir daqui (podem já ter sido editados/vendidos) — desfazer apagaria
        // trabalho do usuário, não só reverter uma mudança de schema.
    }
};
