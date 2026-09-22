<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uma linha por dose esperada do plano do pet — contrato spec 13. `status` NÃO é coluna
 * (regra de negócio 6): aplicada = `applied_at` presente; vencida = `scheduled_for` no
 * passado sem `applied_at` e não `skipped`; senão, agendada. Tudo derivado em leitura,
 * `PetImmunizationDose::isOverdue()`/`isApplied()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pet_immunization_doses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('pet_immunization_plans')->cascadeOnDelete();
            $table->foreignId('protocol_dose_id')->constrained('immunization_protocol_doses')->restrictOnDelete();
            $table->date('scheduled_for');
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('vaccination_id')->nullable()->constrained('vaccinations')->nullOnDelete();
            $table->boolean('skipped')->default(false);
            $table->timestamps();

            $table->index(['plan_id', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pet_immunization_doses');
    }
};
