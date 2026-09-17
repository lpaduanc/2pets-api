<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fluxo de atendimento veterinário — colunas novas de `medical_records`.
 *
 * Contrato fixado: docs/atendimento-veterinario/01-contrato-api-e-taxonomia.md §2.
 *
 * `capillary_refill_time` e `hydration_status` são colunas próprias (fora do JSON
 * `physical_exam`) de propósito: são dado de tendência que vale consulta/gráfico —
 * dentro do JSON ficariam inconsultáveis. Ver §2 do contrato para a desambiguação.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->string('status', 20)->default('draft')->after('appointment_id');
            $table->timestamp('finalized_at')->nullable()->after('status');
            $table->foreignId('finalized_by')->nullable()->after('finalized_at')
                ->constrained('users')->nullOnDelete();

            $table->string('chief_complaint')->nullable()->after('record_date');
            $table->text('chief_complaint_notes')->nullable()->after('chief_complaint');

            $table->json('physical_exam')->nullable()->after('respiratory_rate');
            $table->string('capillary_refill_time', 10)->nullable()->after('physical_exam');
            $table->string('hydration_status', 20)->nullable()->after('capillary_refill_time');
            $table->smallInteger('body_condition_score')->nullable()->after('hydration_status');
            $table->smallInteger('pain_score')->nullable()->after('body_condition_score');

            $table->text('summary_for_tutor')->nullable()->after('notes');

            $table->foreignId('previous_record_id')->nullable()->after('summary_for_tutor')
                ->constrained('medical_records')->nullOnDelete();
            $table->foreignId('follow_up_appointment_id')->nullable()->after('previous_record_id')
                ->constrained('appointments')->nullOnDelete();

            // Painel "pendente de finalizar" (GET professional/medical-records/pending) filtra
            // por professional_id + status — índice composto cobre a query inteira.
            $table->index(['professional_id', 'status']);
        });

        $this->addPostgresCheckConstraints();
    }

    public function down(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->dropIndex(['professional_id', 'status']);

            $table->dropConstrainedForeignId('follow_up_appointment_id');
            $table->dropConstrainedForeignId('previous_record_id');
            $table->dropConstrainedForeignId('finalized_by');

            $table->dropColumn([
                'status',
                'finalized_at',
                'chief_complaint',
                'chief_complaint_notes',
                'physical_exam',
                'capillary_refill_time',
                'hydration_status',
                'body_condition_score',
                'pain_score',
                'summary_for_tutor',
            ]);
        });
    }

    /**
     * `sqlite` (suíte de teste) não suporta `CHECK` adicionado via `ALTER TABLE` — os guards
     * seguem o padrão de `2026_04_23_000007_add_pending_status_to_appointments.php`.
     */
    private function addPostgresCheckConstraints(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'ALTER TABLE medical_records ADD CONSTRAINT medical_records_status_check '.
            "CHECK (status IN ('draft', 'finalized'))"
        );

        DB::statement(
            'ALTER TABLE medical_records ADD CONSTRAINT medical_records_capillary_refill_time_check '.
            "CHECK (capillary_refill_time IS NULL OR capillary_refill_time IN ('lt_2s', '2_3s', 'gt_3s'))"
        );

        DB::statement(
            'ALTER TABLE medical_records ADD CONSTRAINT medical_records_hydration_status_check '.
            "CHECK (hydration_status IS NULL OR hydration_status IN ('normal', 'mild', 'moderate', 'severe'))"
        );

        DB::statement(
            'ALTER TABLE medical_records ADD CONSTRAINT medical_records_body_condition_score_check '.
            'CHECK (body_condition_score IS NULL OR body_condition_score BETWEEN 1 AND 9)'
        );

        DB::statement(
            'ALTER TABLE medical_records ADD CONSTRAINT medical_records_pain_score_check '.
            'CHECK (pain_score IS NULL OR pain_score BETWEEN 0 AND 4)'
        );

        // União de todos os slugs válidos de `chief_complaint` (comuns + só-cão + só-gato +
        // `other`) — a restrição por espécie é responsabilidade da validação (Form Request),
        // não do banco, porque o banco não sabe a espécie do pet sem um JOIN.
        DB::statement(
            'ALTER TABLE medical_records ADD CONSTRAINT medical_records_chief_complaint_check '.
            'CHECK (chief_complaint IS NULL OR chief_complaint IN ('.
            "'vomiting','diarrhea','anorexia','lethargy','pruritus','lameness','cough_sneeze',".
            "'ocular_discharge','aural_discharge','dysuria','skin_wound','routine_checkup',".
            "'revaccination','elective_neutering','pre_anesthetic_evaluation','post_surgical_followup',".
            "'other','bad_breath_dental','weight_change','behavior_change','litter_box_change',".
            "'hairball','respiratory_distress'".
            '))'
        );
    }
};
