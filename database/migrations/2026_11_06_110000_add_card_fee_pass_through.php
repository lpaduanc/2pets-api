<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Repasse opcional da taxa ao cliente, por faixa — docs/gap-petflow/PLANO.md, 1.1 — e faixa que
 * sobrevive à edição.
 *
 * - `payment_method_fee_tiers.pass_to_customer`: a faixa cobra do cliente um acréscimo que deixa o
 *   líquido da clínica ≥ o valor-base.
 * - `payment_method_fee_tiers.deleted_at`: o PUT das faixas apagava tudo e recriava, e o
 *   `sale_receipts.fee_tier_id` (`nullOnDelete`) perdia o vínculo. Agora a faixa removida que já
 *   foi usada por um recebimento é só arquivada (soft delete); a nunca usada é apagada de fato.
 * - `surcharge_amount` em `sale_receipts` e `sales`: o acréscimo NÃO é item da venda — item seria
 *   atingido por desconto percentual, comissão e baixa de estoque. A venda guarda a soma dos
 *   acréscimos dos recebimentos e o total passa a ser `subtotal − desconto + acréscimo`.
 *
 * Arquivo separado do 2026_11_06_100000 porque o banco de dev já rodou aquele.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_method_fee_tiers', function (Blueprint $table): void {
            $table->boolean('pass_to_customer')->default(false)->after('interval_days');
            $table->softDeletes();
        });

        Schema::table('sale_receipts', function (Blueprint $table): void {
            $table->decimal('surcharge_amount', 14, 2)->default(0)->after('amount');
        });

        Schema::table('sales', function (Blueprint $table): void {
            $table->decimal('surcharge_amount', 14, 2)->default(0)->after('discount_amount');
        });
    }

    public function down(): void
    {
        // Sem `deleted_at`, faixa arquivada voltaria a valer e poderia sobrepor a vigente: apaga
        // de fato (o `nullOnDelete` solta o `fee_tier_id` dos recebimentos — o comportamento antigo).
        DB::table('payment_method_fee_tiers')->whereNotNull('deleted_at')->delete();

        // O total gravado de venda com acréscimo continua incluindo o acréscimo (é o que o
        // cliente pagou); só o próximo `recalculateTotals()` do código antigo o descartaria.
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropColumn('surcharge_amount');
        });

        Schema::table('sale_receipts', function (Blueprint $table): void {
            $table->dropColumn('surcharge_amount');
        });

        Schema::table('payment_method_fee_tiers', function (Blueprint $table): void {
            $table->dropSoftDeletes();
            $table->dropColumn('pass_to_customer');
        });
    }
};
