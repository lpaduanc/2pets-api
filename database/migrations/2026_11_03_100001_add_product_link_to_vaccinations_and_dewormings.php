<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consolidação de estoque — docs/gap-simplesvet/specs/produtos-estoque-consolidado-spec.md.
 *
 * `product_id`/`product_batch_id` substituem `inventory_id` como vínculo de estoque do ato
 * clínico. `inventory_id` NÃO é removido nesta migration (fica `@deprecated` nos models, ver
 * `2026_11_03_100002_migrate_inventory_data_to_products`, que faz o backfill linha a linha a
 * partir do mapa de migração — nunca por casamento de nome, ver item 5.4 da spec) — apagar a
 * coluna agora quebraria o histórico de quem ainda a lê.
 *
 * Nulo continua sendo o caso normal (tutor auto-relato, campanha, vet volante sem controle de
 * estoque na plataforma) — mesma semântica de `inventory_id` que este campo substitui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vaccinations', function (Blueprint $table): void {
            $table->foreignId('product_id')->nullable()->after('inventory_id')
                ->constrained()->nullOnDelete();
            $table->foreignId('product_batch_id')->nullable()->after('product_id')
                ->constrained('product_batches')->nullOnDelete();
        });

        Schema::table('pet_dewormings', function (Blueprint $table): void {
            $table->foreignId('product_id')->nullable()->after('inventory_id')
                ->constrained()->nullOnDelete();
            $table->foreignId('product_batch_id')->nullable()->after('product_id')
                ->constrained('product_batches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vaccinations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_batch_id');
            $table->dropConstrainedForeignId('product_id');
        });

        Schema::table('pet_dewormings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_batch_id');
            $table->dropConstrainedForeignId('product_id');
        });
    }
};
