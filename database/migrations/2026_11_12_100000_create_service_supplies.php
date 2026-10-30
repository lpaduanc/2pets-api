<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custo do serviço (ficha técnica de insumos) — docs/gap-petflow/PLANO.md, 1.3.
 *
 * - `services.direct_cost`: custo fixo por execução que não é estoque (terceiro, lavanderia,
 *   taxa de laboratório). Somado ao custo dos insumos dá o `Service::unitCost()`.
 * - `products.is_supply`: insumo consumido no serviço — fica fora do catálogo do PDV, mas continua
 *   no estoque (compra, kardex, inventário).
 * - `service_supplies`: a ficha. `quantity` é na unidade de ESTOQUE do produto (ml, g, un), 3 casas.
 *   Par `organization_id`/`professional_id` copiado do serviço, como o resto do módulo comercial.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->decimal('direct_cost', 12, 2)->default(0)->after('price');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('is_supply')->default(false)->after('purpose');
        });

        Schema::create('service_supplies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('professional_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->timestamps();

            $table->unique(['service_id', 'product_id']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_supplies');

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('is_supply');
        });

        Schema::table('services', function (Blueprint $table): void {
            $table->dropColumn('direct_cost');
        });
    }
};
