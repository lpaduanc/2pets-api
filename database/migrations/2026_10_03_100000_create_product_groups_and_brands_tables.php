<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contrato docs/gap-simplesvet/08-produtos-precificacao-lista-precos.md — "Grupos e marcas".
 *
 * Grupo e marca deixam de ser texto solto e viram cadastro próprio porque o BI (doc 20) os
 * consome como DIMENSÃO (`Vendas por grupo de produtos e serviços`, `Marcas mais vendidas`):
 * agrupar por string livre produz "Royal Canin", "royal canin" e "Royal Canin " como três
 * marcas distintas no relatório.
 *
 * `organization_id` (não `company_id` do documento): a empresa neste código é `organizations`
 * — `companies` é a empresa parceira de benefício pet (B2B), coisa diferente. Nullable pelo
 * mesmo motivo do grupo COMERCIAL inteiro (`2026_09_14_100000_add_organization_id_to_commercial_tables`):
 * vet volante não tem organização e continua dono do próprio cadastro via `professional_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('name');

            // Árvore rasa (grupo → subgrupo). Sem CHECK de profundidade: o SimplesVet
            // permite qualquer nível e a regra de negócio real é "folha recebe produto".
            $table->foreignId('parent_id')->nullable()->constrained('product_groups')->nullOnDelete();

            // Markup padrão herdado por produto novo criado dentro do grupo — critério de
            // aceite do doc 08. Nullable = "não herda nada", diferente de 0%.
            $table->decimal('default_markup_percent', 8, 4)->nullable();

            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'active']);
            $table->index(['professional_id', 'active']);
            $table->index('parent_id');
        });

        Schema::create('brands', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'active']);
            $table->index(['professional_id', 'active']);
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Nome único POR DONO e só entre os vivos — índice parcial, porque `softDeletes`
        // faria um nome excluído bloquear a recriação do mesmo nome para sempre. Mesmo
        // padrão de `2026_09_16_090100_add_partial_unique_indexes_for_soft_deleted_documents`.
        foreach (['product_groups', 'brands'] as $table) {
            Schema::getConnection()->statement(
                "CREATE UNIQUE INDEX {$table}_org_name_unique ON {$table} (organization_id, lower(name)) WHERE deleted_at IS NULL AND organization_id IS NOT NULL"
            );
            Schema::getConnection()->statement(
                "CREATE UNIQUE INDEX {$table}_professional_name_unique ON {$table} (professional_id, lower(name)) WHERE deleted_at IS NULL AND organization_id IS NULL"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('brands');
        Schema::dropIfExists('product_groups');
    }
};
