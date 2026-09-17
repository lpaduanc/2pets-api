<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `prescription_items` substitui `prescriptions.medications` (JSON) — contrato
 * docs/atendimento-veterinario/03-contrato-receituario.md §2.
 *
 * Sem soft delete de propósito: item de prescrição ainda não emitida some junto com a
 * edição (a receita inteira é substituída); item de prescrição já emitida nunca é apagado
 * porque a prescrição deixa de ser editável (ver migration de imutabilidade).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prescription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prescription_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('position')->default(0);

            // Nunca obrigatório: a maioria das prescrições cita medicamento fora do
            // estoque da própria clínica (contrato §2.4 do doc de domínio).
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();

            $table->string('active_ingredient')->nullable();
            $table->string('commercial_name')->nullable();
            $table->string('concentration')->nullable();

            $table->string('pharmaceutical_form', 30)->nullable();
            $table->string('form_notes')->nullable();

            $table->string('route', 20)->nullable();
            $table->string('route_notes')->nullable();

            $table->decimal('dose_value', 10, 3)->nullable();
            $table->string('dose_unit', 20)->nullable();
            $table->decimal('dose_per_kg', 10, 3)->nullable();
            $table->boolean('dose_calculated')->default(false);

            $table->string('frequency', 20)->nullable();
            $table->smallInteger('frequency_custom_hours')->nullable();
            $table->string('frequency_notes')->nullable();

            $table->string('duration_text')->nullable();
            $table->boolean('is_continuous_use')->default(false);

            $table->string('quantity_to_dispense')->nullable();
            $table->text('instructions_for_tutor')->nullable();
            $table->boolean('is_controlled')->default(false);

            $table->timestamps();

            // FK do Postgres NÃO cria índice sozinha — ver
            // `.claude/agent-memory/backend-specialist/busca-pet-vet-indices.md`. A listagem
            // de itens de uma receita (`prescription->items()`) e a busca textual
            // (`PrescriptionSearchFilter`) sempre filtram por esta coluna primeiro.
            $table->index('prescription_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription_items');
    }
};
