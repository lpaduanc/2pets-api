<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Linha de venda marcada como brinde, cortesia ou retorno (docs/gap-petflow/PLANO.md, 1.4). A
 * marcação é da LINHA, não do produto: o mesmo item é vendido num dia e dado de cortesia no outro.
 * A linha marcada tem líquido zero, sai do rateio do desconto do cabeçalho e das regras de teto e
 * de custo; quantidade, estoque e custo congelado não mudam (o custo vira despesa de cortesia na
 * 1.5). `courtesy_original_unit_price` guarda o preço para desmarcar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->string('courtesy_kind', 20)->nullable();
            $table->string('courtesy_reason', 255)->nullable();
            $table->decimal('courtesy_original_unit_price', 14, 2)->nullable();
            $table->foreignId('courtesy_marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('courtesy_marked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('courtesy_marked_by');
            $table->dropColumn(['courtesy_kind', 'courtesy_reason', 'courtesy_original_unit_price', 'courtesy_marked_at']);
        });
    }
};
