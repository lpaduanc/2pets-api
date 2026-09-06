<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 4 do plano de otimizacao — dominio "commerce" (orders, invoices, inventories,
 * payments, subscriptions, coupons).
 *
 * A maioria das FKs de commerce ja tinha cobertura (orders_user_id_status_index,
 * payments_user_id_status_index, products_professional_id_is_active_index,
 * cart_items_cart_id_product_id_unique, carts_user_id_professional_id_unique). Depois de
 * conferir por grep os usos reais em app/, sobraram tres.
 *
 * Descartados nesta rodada (sem query nomeada — ver auto-review do relato da fase):
 * orders.professional_id (nenhum grep encontrou leitura por essa coluna, so escrita),
 * subscriptions.subscription_plan_id (belongsTo nunca acessado na direcao reversa),
 * payments.invoice_id (Invoice nao tem relacao payments(), nenhuma leitura), deliveries.order_id
 * (Order::delivery() definido mas nunca invocado em app/), cart_items.product_id e
 * carts.professional_id (sempre filtrados junto com a outra coluna do unique composto, ja
 * cobertos).
 *
 * `CONCURRENTLY` nao pode rodar dentro de uma transacao.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->createInvoicesProfessionalIdIndex();
        $this->createInventoriesProfessionalIdIndex();
        $this->createOrderItemsOrderIdIndex();
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_order_items_order_id');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_inventories_professional_id');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_invoices_professional_id');
    }

    /**
     * invoices.professional_id nao tinha indice. Queries nomeadas:
     * app/Http/Controllers/Api/InvoiceController.php:16,57,64,89 (`Invoice::where('professional_id',
     * $request->user()->id)`, listagem/detalhe/update do profissional) e
     * app/Services/Report/RevenueReportService.php:17. invoices nao tem deleted_at -> indice cheio.
     */
    private function createInvoicesProfessionalIdIndex(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_invoices_professional_id ON invoices (professional_id)');
    }

    /**
     * inventories.professional_id nao tinha indice. Query nomeada:
     * app/Http/Controllers/Api/InventoryController.php:15,45,52,73 — mesmo padrao de listagem
     * por dono. inventories nao tem deleted_at -> indice cheio.
     */
    private function createInventoriesProfessionalIdIndex(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_inventories_professional_id ON inventories (professional_id)');
    }

    /**
     * order_items.order_id nao tinha indice. Query nomeada: app/Models/Order.php:83 —
     * `foreach ($this->items as $item)` dentro de `cancel()` (hasMany OrderItem::class definido
     * em Order.php:47-50), usado para repor estoque ao cancelar pedido. FK e ON DELETE CASCADE
     * (criterio b) numa tabela que cresce com todo pedido do marketplace (criterio c).
     */
    private function createOrderItemsOrderIdIndex(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_order_items_order_id ON order_items (order_id)');
    }
};
