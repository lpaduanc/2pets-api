<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Foto diária do estoque valorizado (PLANO 3.6): Σ estoque × custo médio e Σ estoque × preço por
 * escopo (organização, ou vet volante sem organização), um registro por dia — o widget "Custo
 * estocado / Venda estocada" compara o atual com a foto do último dia do mês anterior.
 * Gravada pelo comando `stock:snapshot-valuation` (agendado às 23:55, idempotente por dia).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_valuation_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->date('snapshot_date');
            $table->decimal('cost_value', 16, 2);
            $table->decimal('sale_value', 16, 2);
            $table->unsignedInteger('product_count');
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX stock_valuation_snapshots_scope_date ON stock_valuation_snapshots (COALESCE(organization_id, 0), COALESCE(professional_id, 0), snapshot_date)');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_valuation_snapshots');
    }
};
