<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Itens CONGELADOS do documento fiscal — spec docs/gap-petflow/specs/fase4-fiscal-sem-envio.md
 * §3.1/§3.2 (PLANO §3, item 6: snapshot). Tudo o que o XML vai levar fica aqui no momento da
 * emissão: perfil, CFOP, CST e alíquotas podem mudar depois no cadastro sem reescrever o
 * passado — e a devolução espelha exatamente estes números.
 *
 * Σ `total` dos itens = `fiscal_documents.total`, centavo a centavo (rateio pelo maior resto).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_document_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fiscal_document_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->foreignId('sale_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sale_return_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_return_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->char('ncm', 8)->nullable();
            $table->char('cest', 7)->nullable();
            $table->string('unit', 10);
            $table->decimal('quantity', 14, 4);
            $table->decimal('unit_price', 14, 4);
            $table->decimal('gross_total', 14, 2);
            $table->decimal('discount_share', 14, 2)->default(0);
            $table->decimal('surcharge_share', 14, 2)->default(0);
            $table->decimal('total', 14, 2);
            $table->char('cfop', 4)->nullable();
            $table->char('origin', 1)->nullable();
            $table->string('csosn', 3)->nullable();
            $table->string('cst', 2)->nullable();
            $table->decimal('icms_base', 14, 2)->default(0);
            $table->decimal('icms_rate', 7, 4)->default(0);
            $table->decimal('icms_amount', 14, 2)->default(0);
            $table->decimal('icms_sn_credit_rate', 7, 4)->default(0);
            $table->decimal('icms_sn_credit_amount', 14, 2)->default(0);
            $table->decimal('fcp_rate', 7, 4)->default(0);
            $table->decimal('fcp_amount', 14, 2)->default(0);
            $table->string('pis_cst', 2)->nullable();
            $table->decimal('pis_base', 14, 2)->default(0);
            $table->decimal('pis_rate', 7, 4)->default(0);
            $table->decimal('pis_amount', 14, 2)->default(0);
            $table->string('cofins_cst', 2)->nullable();
            $table->decimal('cofins_base', 14, 2)->default(0);
            $table->decimal('cofins_rate', 7, 4)->default(0);
            $table->decimal('cofins_amount', 14, 2)->default(0);
            $table->decimal('ipi_amount', 14, 2)->default(0);
            $table->decimal('icms_st_amount', 14, 2)->default(0);
            $table->string('benefit_code', 10)->nullable();
            $table->decimal('icms_base_reduction_percent', 7, 4)->default(0);
            $table->foreignId('tax_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->string('profile_source', 15)->nullable();
            $table->timestamps();

            $table->index('fiscal_document_id');
            $table->index('sale_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_document_items');
    }
};
