<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configurações do acréscimo de repasse da taxa do cartão (docs/gap-petflow/PLANO.md, 1.1) que o
 * contador de cada clínica decide — ficam em `organizations`, junto das demais fiscais (doc 05):
 *
 * - `card_surcharge_on_invoice`: o acréscimo entra no documento fiscal ("outras despesas
 *   acessórias" — vOutro na NF-e/NFC-e; somado ao valor do serviço na NFS-e). Padrão LIGADO: o
 *   cliente pagou o valor, e o documento que bate com o total da venda é o caso comum.
 * - `card_surcharge_return_policy`: `keep` (o acréscimo não volta na devolução — comportamento
 *   anterior) ou `proportional` (volta a parte dos itens devolvidos).
 * - `fiscal_documents.surcharge_amount`: a parte do acréscimo que coube a cada documento, para
 *   Σ totais dos documentos = total da venda, centavo a centavo.
 * - `sale_returns.surcharge_refund`: quanto do acréscimo a devolução levou — base para nunca
 *   devolver mais do que o acréscimo cobrado em devoluções sucessivas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->boolean('card_surcharge_on_invoice')->default(true);
            $table->string('card_surcharge_return_policy', 20)->default('keep');
        });

        Schema::table('fiscal_documents', function (Blueprint $table): void {
            $table->decimal('surcharge_amount', 14, 2)->default(0)->after('total');
        });

        Schema::table('sale_returns', function (Blueprint $table): void {
            $table->decimal('surcharge_refund', 14, 2)->default(0)->after('total');
        });
    }

    public function down(): void
    {
        Schema::table('sale_returns', function (Blueprint $table): void {
            $table->dropColumn('surcharge_refund');
        });

        Schema::table('fiscal_documents', function (Blueprint $table): void {
            $table->dropColumn('surcharge_amount');
        });

        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn(['card_surcharge_on_invoice', 'card_surcharge_return_policy']);
        });
    }
};
