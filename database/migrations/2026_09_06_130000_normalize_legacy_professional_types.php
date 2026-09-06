<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrige as únicas 4 linhas de `professionals.professional_type` fora da taxonomia
 * canônica de 7 tipos (ver `docs/taxonomia-professional-type.md`):
 * - `veterinarian` (3 linhas): forma longa nunca canônica, usada só pelo enum/UI antigos.
 * - `vet_freelancer` (1 linha): não é tipo de negócio — é o nome do role do Spatie,
 *   gravado por engano na coluna errada pelo `DemoDataSeeder`.
 *
 * As demais ~45.000 linhas já usam a forma curta canônica e não são tocadas.
 */
return new class extends Migration
{
    public function up(): void
    {
        // O banco de teste (`pgsql_test`) roda migração de verdade — este guard só pula
        // em drivers não-Postgres (nenhum hoje, mas é a convenção do repo para
        // DB::statement em migration).
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::table('professionals')
            ->whereIn('professional_type', ['veterinarian', 'vet_freelancer'])
            ->update(['professional_type' => 'vet']);
    }

    public function down(): void
    {
        // No-op: não há como distinguir de volta quais linhas eram `veterinarian` vs
        // `vet_freelancer` depois do merge — e o valor canônico (`vet`) é o correto.
    }
};
