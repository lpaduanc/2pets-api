<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consolidação de estoque — docs/gap-simplesvet/specs/produtos-estoque-consolidado-spec.md.
 *
 * `products.immunization_product_id` liga o item de estoque (marca/lote comprado) à identidade
 * clínica do calendário (`ImmunizationProduct`, spec 13) — vários produtos podem apontar para a
 * mesma identidade (marcas/fornecedores diferentes da "mesma vacina"), por isso o FK mora no
 * lado N, não em `immunization_products`.
 *
 * `legacy_inventory_id` (em `products` e em `product_batches`) é só rastreabilidade/idempotência
 * da migração de dados de `inventories` (item 5 da spec: "um `Inventory` é um lote implícito") —
 * nunca preenchido para um cadastro novo feito depois desta migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('immunization_product_id')->nullable()->after('brand_id')
                ->constrained()->nullOnDelete();
            $table->foreignId('legacy_inventory_id')->nullable()->after('immunization_product_id')
                ->constrained('inventories')->nullOnDelete();
        });

        Schema::table('product_batches', function (Blueprint $table): void {
            $table->foreignId('legacy_inventory_id')->nullable()->after('unit_cost')
                ->constrained('inventories')->nullOnDelete();
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $connection = Schema::getConnection();

        // Idempotência da migração de dado: uma linha de `inventories` vira NO MÁXIMO um
        // produto/lote. Índice parcial porque a coluna é nula para todo cadastro novo.
        $connection->statement(
            'CREATE UNIQUE INDEX products_legacy_inventory_unique ON products (legacy_inventory_id) WHERE legacy_inventory_id IS NOT NULL'
        );
        $connection->statement(
            'CREATE UNIQUE INDEX product_batches_legacy_inventory_unique ON product_batches (legacy_inventory_id) WHERE legacy_inventory_id IS NOT NULL'
        );
        // Seletor clínico (spec item 4): "todo produto que aplica a vacina X" é a query mais
        // comum sobre esta coluna.
        $connection->statement(
            'CREATE INDEX products_immunization_product_index ON products (immunization_product_id) WHERE immunization_product_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            $connection = Schema::getConnection();
            $connection->statement('DROP INDEX IF EXISTS products_immunization_product_index');
            $connection->statement('DROP INDEX IF EXISTS product_batches_legacy_inventory_unique');
            $connection->statement('DROP INDEX IF EXISTS products_legacy_inventory_unique');
        }

        Schema::table('product_batches', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('legacy_inventory_id');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('legacy_inventory_id');
            $table->dropConstrainedForeignId('immunization_product_id');
        });
    }
};
