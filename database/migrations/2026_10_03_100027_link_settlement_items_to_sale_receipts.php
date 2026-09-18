<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fecha a FK que `2026_10_03_100012_create_acquirer_settlements_tables` deixou pendente:
 * `acquirer_settlement_items.sale_receipt_id` → `sale_receipts.id`.
 *
 * A coluna nasceu solta porque `sale_receipts` só é criada no doc 01
 * (`2026_10_03_100021_create_sales_tables`), que roda depois. Separar a FK numa migration
 * própria é preferível a inverter a ordem dos documentos: o doc 04 continua legível sozinho,
 * e quem for reconstruir o banco do zero tem a integridade garantida no fim da sequência.
 *
 * `cascadeOnDelete`: apagar um recebimento (só acontece em cancelamento de venda, que remove
 * a venda inteira) tem que levar junto a linha de conciliação que o referenciava — um depósito
 * apontando para um recebimento inexistente seria pior do que um depósito com um item a menos,
 * que o próprio `AcquirerReconciliationService` marca como divergente na conferência seguinte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acquirer_settlement_items', function (Blueprint $table): void {
            $table->foreign('sale_receipt_id')
                ->references('id')
                ->on('sale_receipts')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('acquirer_settlement_items', function (Blueprint $table): void {
            $table->dropForeign(['sale_receipt_id']);
        });
    }
};
