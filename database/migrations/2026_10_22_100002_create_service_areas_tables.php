<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Item 21 do backlog gap-simplesvet — `service_areas` MÍNIMO (não o `AppointmentType`
 * completo do item 14): restringe elegibilidade ("profissional que só atende Banho e Tosa
 * não recebe agendamento de Consulta Geral") sem construir a taxonomia inteira de tipos de
 * atendimento. Mesmo padrão por-dono de `product_groups`/`brands`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_areas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('color', 7)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'active']);
            $table->index(['professional_id', 'active']);
        });

        Schema::create('organization_member_service_areas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_area_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['organization_member_id', 'service_area_id'], 'org_member_service_area_unique');
        });

        // Regra 2 da spec: restrição só se aplica a quem TEM área cadastrada — um serviço
        // sem `service_area_id` (todos os existentes hoje) não fica órfão de elegibilidade.
        Schema::table('services', function (Blueprint $table): void {
            $table->foreignId('service_area_id')->nullable()->after('category')->constrained()->nullOnDelete();
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'CREATE UNIQUE INDEX service_areas_org_name_unique ON service_areas (organization_id, lower(name)) WHERE deleted_at IS NULL AND organization_id IS NOT NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX service_areas_professional_name_unique ON service_areas (professional_id, lower(name)) WHERE deleted_at IS NULL AND organization_id IS NULL'
        );
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('service_area_id');
        });
        Schema::dropIfExists('organization_member_service_areas');
        Schema::dropIfExists('service_areas');
    }
};
