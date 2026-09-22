<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cadastro de agenda "tipo de atendimento" — contrato docs/gap-simplesvet/specs/
 * 14-tipos-atendimento-modelos-prontuario-spec.md. Segue o MESMO padrão por-dono das seis
 * tabelas do item 23 (`2026_10_22_100000_create_configurable_catalogs_tables.php`) —
 * `organization_id` OU `professional_id`, nunca os dois nulos — com duas colunas próprias de
 * agenda (`category`, obrigatória, valor de `ServiceCategory`; `default_duration_minutes`;
 * `color`). Puramente aditivo: apagar a tabela inteira não afeta
 * `MedicalRecordEncounterResolver`/`ServiceCategory` (regra de negócio 2 da spec).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('category', 30);
            $table->unsignedSmallInteger('default_duration_minutes')->nullable();
            $table->string('color', 20)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'active']);
            $table->index(['professional_id', 'active']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Nome único por dono, entre os vivos — mesmo índice parcial do item 23.
        DB::statement(
            'CREATE UNIQUE INDEX appointment_types_org_name_unique ON appointment_types (organization_id, lower(name)) WHERE deleted_at IS NULL AND organization_id IS NOT NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX appointment_types_professional_name_unique ON appointment_types (professional_id, lower(name)) WHERE deleted_at IS NULL AND organization_id IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_types');
    }
};
