<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vínculo mínimo entre o item de serviço da venda e o agendamento que o executou
 * (docs/gap-petflow/PLANO.md, 1.3). Sem ele, o banho concluído na agenda e cobrado no PDV baixava
 * os insumos duas vezes. Com o vínculo, o insumo sai SÓ pela conclusão do agendamento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->foreignId('appointment_id')->nullable()->after('sellable_id')->constrained()->nullOnDelete();
            $table->index('appointment_id');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('appointment_id');
        });
    }
};
