<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Centro de custo e rateio (docs/gap-petflow/PLANO.md, 2.4).
 *
 * - `cost_centers`: cadastro da organização (ou do vet volante), por tipo.
 * - `cost_center_id` em produto, serviço e grupo: produto/serviço sem centro herdam o do grupo
 *   (`CostCenterResolver`, um lugar só).
 * - `financial_entry_allocations`: rateio do lançamento; Σ percent = 100,0000 exato e amount =
 *   `allocate(net_amount, pesos = percent)`. Lançamento sem linha aqui é "Não rateado".
 * - `financial_categories.default_allocation`: o rateio padrão da categoria
 *   (`[{cost_center_id, percent}]`), aplicado ao lançamento que nasce sem rateio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_centers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('type', 30);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'active']);
            $table->index(['professional_id', 'active']);
        });

        DB::statement("ALTER TABLE cost_centers ADD CONSTRAINT cost_centers_type_check CHECK (type IN ('store', 'grooming', 'clinic', 'hotel', 'administrative'))");

        foreach (['products', 'services', 'product_groups'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->foreignId('cost_center_id')->nullable()->constrained()->nullOnDelete();
            });
        }

        Schema::create('financial_entry_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('financial_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cost_center_id')->constrained();
            $table->decimal('percent', 7, 4);
            $table->decimal('amount', 14, 2);
            $table->timestamps();

            $table->unique(['financial_entry_id', 'cost_center_id']);
            $table->index('cost_center_id');
        });

        DB::statement('ALTER TABLE financial_entry_allocations ADD CONSTRAINT financial_entry_allocations_percent_check CHECK (percent > 0 AND percent <= 100)');

        Schema::table('financial_categories', function (Blueprint $table): void {
            $table->json('default_allocation')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('financial_categories', function (Blueprint $table): void {
            $table->dropColumn('default_allocation');
        });

        Schema::dropIfExists('financial_entry_allocations');

        foreach (['products', 'services', 'product_groups'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropConstrainedForeignId('cost_center_id');
            });
        }

        Schema::dropIfExists('cost_centers');
    }
};
