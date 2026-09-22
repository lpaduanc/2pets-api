<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Box/leito + triagem na admissão — contrato docs/gap-simplesvet/specs/
 * 12-internacao-mapa-execucao-spec.md §1/§2. `box_id` aponta para o catálogo do item 23
 * (`hospitalization_boxes`, já entregue) — este documento só liga `Hospitalization` a ele.
 * `RESTRICT` (não `cascadeOnDelete`): apagar um box com internação histórica apontando para
 * ele não pode arrastar o histórico clínico junto.
 *
 * Índice único parcial (regra de negócio 1): um box só pode ter UMA internação `active` por
 * vez. Não expressável em CHECK de coluna simples — é sempre um índice parcial no Postgres.
 * `risk_level` é sempre opcional (regra de negócio 2) — nenhuma trava de aplicação a exige.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hospitalizations', function (Blueprint $table): void {
            $table->foreignId('box_id')->nullable()->after('appointment_id')
                ->constrained('hospitalization_boxes')->restrictOnDelete();
            $table->string('risk_level', 20)->nullable()->after('reason');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'CREATE UNIQUE INDEX hospitalizations_active_box_unique ON hospitalizations (box_id) WHERE status = \'active\' AND box_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::table('hospitalizations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('box_id');
            $table->dropColumn('risk_level');
        });
    }
};
