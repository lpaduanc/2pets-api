<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Receita é documento clínico com valor legal — `DELETE` físico apaga prova de prescrição
 * (inclusive de medicamento controlado) sem deixar rastro. A regra "soft delete em tudo" do
 * `CLAUDE.md` existe exatamente para este caso.
 *
 * Sem índice em `deleted_at` de propósito: toda consulta da tela entra por
 * `prescriptions_professional_id_index` (no seed de 500 mil linhas, no máximo 12 receitas por
 * profissional), e o `deleted_at IS NULL` sai de graça como filtro sobre esse punhado de linhas.
 * Índice aqui só custaria escrita — ver o portão de `idx_scan = 0` da Fase 4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prescriptions', function (Blueprint $table): void {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('prescriptions', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
