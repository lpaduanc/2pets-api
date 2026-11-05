<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Estorno de UM recebimento (docs/gap-petflow/PLANO.md, 2.3).
 *
 * - `sale_receipts.cash_register_id`: o caixa em que o recebimento entrou — o estorno volta nele
 *   se ainda estiver aberto. Recebimento antigo fica `null` (o serviço procura o movimento).
 * - `sale_receipts.cancel_reason`/`cancelled_by`: motivo e autor do estorno; o instante é o
 *   `reversed_at` que o cancelamento da venda já carimba (um campo só para "estornado").
 * - `commission_settlement_items.reversed_at`/`reversal_reason`: comissão de item estornada,
 *   sem apagar o histórico do fechamento. O `unique(sale_item_id)` vira único só entre os NÃO
 *   estornados — a venda paga de novo pode entrar num fechamento novo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_receipts', function (Blueprint $table): void {
            $table->foreignId('cash_register_id')->nullable()->after('account_id')->constrained()->nullOnDelete();
            $table->string('cancel_reason', 500)->nullable()->after('reversed_at');
            $table->foreignId('cancelled_by')->nullable()->after('cancel_reason')->constrained('users')->nullOnDelete();
        });

        Schema::table('commission_settlement_items', function (Blueprint $table): void {
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason', 500)->nullable();
            $table->dropUnique(['sale_item_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX commission_settlement_items_active_item_unique ON commission_settlement_items (sale_item_id) WHERE reversed_at IS NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS commission_settlement_items_active_item_unique');
        }

        Schema::table('commission_settlement_items', function (Blueprint $table): void {
            $table->dropColumn(['reversed_at', 'reversal_reason']);
            $table->unique('sale_item_id');
        });

        Schema::table('sale_receipts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropConstrainedForeignId('cash_register_id');
            $table->dropColumn('cancel_reason');
        });
    }
};
