<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contrato docs/atendimento-veterinario/11-internacao-no-fluxo-de-faturamento.md §2.1.
 *
 * Diária de internação é lançada manualmente, às vezes retroativa (a recepção lança hoje
 * as diárias de dois dias atrás). `created_at` registraria a data do LANÇAMENTO, não a
 * data do FATO GERADOR — duas linhas "Diária de internação" no mesmo dia, nenhuma no dia
 * que elas de fato cobrem. `reference_date` é nullable: só a diária a usa por ora, os
 * outros itens de comanda continuam sem data própria (`created_at` basta para eles).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointment_charges', function (Blueprint $table) {
            $table->date('reference_date')->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('appointment_charges', function (Blueprint $table) {
            $table->dropColumn('reference_date');
        });
    }
};
