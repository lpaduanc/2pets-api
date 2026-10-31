<?php

use App\Enums\CardFeeReferenceMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Precificação — docs/gap-petflow/PLANO.md, 1.4.
 *
 * - `organizations.effective_sales_tax_rate`: alíquota efetiva do Simples informada pela clínica
 *   (decisão 3 do plano, padrão "informada"); `null` = não informada, o preço mínimo usa 0 e avisa.
 * - `organizations.card_fee_reference_mode`: qual taxa de cartão entra no preço mínimo — média
 *   ponderada de 90 dias (padrão, decisão 2) ou pior caso (maior MDR cadastrado).
 * - `products/services.max_discount_percent`: teto do desconto efetivo da linha na venda;
 *   `null` = sem limite (comportamento anterior).
 * - `product_price_history`: append-only, uma linha por mudança de preço do produto, com a
 *   origem (cadastro, compra, importação). Nunca é atualizada nem apagada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->decimal('effective_sales_tax_rate', 8, 4)->nullable();
            $table->string('card_fee_reference_mode', 20)->default(CardFeeReferenceMode::WEIGHTED_90D->value);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->decimal('max_discount_percent', 8, 4)->nullable();
        });

        Schema::table('services', function (Blueprint $table): void {
            $table->decimal('max_discount_percent', 8, 4)->nullable();
        });

        Schema::create('product_price_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 12, 2);
            $table->decimal('cost', 12, 4)->default(0);
            $table->decimal('markup_percent', 12, 4)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 20);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['product_id', 'created_at']);
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_price_history');

        Schema::table('services', function (Blueprint $table): void {
            $table->dropColumn('max_discount_percent');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('max_discount_percent');
        });

        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn(['effective_sales_tax_rate', 'card_fee_reference_mode']);
        });
    }
};
