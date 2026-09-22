<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contrato docs/gap-simplesvet/06-compras-fornecedores-xml.md — "Fornecedores".
 *
 * Substitui o `inventories.supplier` (string livre) como dono do relacionamento de compra. A
 * coluna antiga NÃO é removida: é do insumo clínico, que continua com seu próprio cadastro
 * (ver a nota de `2026_10_03_100001_add_commercial_fields_to_products_table`).
 *
 * O contato do REPRESENTANTE (`sales_rep_*`) é separado do da empresa: no SimplesVet a
 * listagem mostra "Vendedor / Telefone do vendedor", e é com ele que a clínica fala no dia a
 * dia — o telefone da distribuidora é o do SAC.
 *
 * `supplier_products` é a memória de "código do fornecedor → nosso produto". Nasce da
 * primeira importação de XML confirmada e é o segundo critério de casamento das seguintes
 * (depois do GTIN): muito item de distribuidora vem sem código de barras.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->cascadeOnDelete();

            $table->string('legal_name');
            $table->string('trade_name')->nullable();
            // Só dígitos (CNPJ 14 ou CPF 11). É a chave de casamento da NF-e importada.
            $table->string('document', 14)->nullable();
            $table->string('state_registration', 20)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();

            $table->string('sales_rep_name')->nullable();
            $table->string('sales_rep_phone', 20)->nullable();
            $table->string('sales_rep_email')->nullable();

            $table->string('address_zip', 8)->nullable();
            $table->string('address_street')->nullable();
            $table->string('address_number', 20)->nullable();
            $table->string('address_complement')->nullable();
            $table->string('address_district')->nullable();
            $table->string('address_city')->nullable();
            $table->string('address_state', 2)->nullable();

            $table->string('payment_terms', 100)->nullable();
            // Prazo de entrega em dias — entra na regra "repor" da análise de estoque (doc 07).
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'active']);
            $table->index(['professional_id', 'active']);
        });

        Schema::create('supplier_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('supplier_product_code', 60);
            $table->timestamps();

            $table->unique(['supplier_id', 'supplier_product_code']);
            $table->index('product_id');
        });

        // O produto passa a lembrar de quem foi comprado por último — é o fornecedor cujo
        // prazo de entrega a análise de estoque usa.
        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('last_supplier_id')->nullable()->after('brand_id')->constrained('suppliers')->nullOnDelete();
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Um CNPJ por dono, entre os vivos. Parcial pelo mesmo motivo de `product_groups`:
        // fornecedor excluído não pode bloquear o recadastro.
        $connection = Schema::getConnection();
        $connection->statement(
            'CREATE UNIQUE INDEX suppliers_org_document_unique ON suppliers (organization_id, document) WHERE deleted_at IS NULL AND organization_id IS NOT NULL AND document IS NOT NULL'
        );
        $connection->statement(
            'CREATE UNIQUE INDEX suppliers_professional_document_unique ON suppliers (professional_id, document) WHERE deleted_at IS NULL AND organization_id IS NULL AND document IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('last_supplier_id');
        });
        Schema::dropIfExists('supplier_products');
        Schema::dropIfExists('suppliers');
    }
};
