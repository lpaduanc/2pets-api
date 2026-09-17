<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Consulta autorizada por agendamento" — contrato
 * docs/atendimento-veterinario/08-consulta-autorizada-por-agendamento.md §C.
 *
 * O que o tutor informa NA consulta (peso, alimentação, idade etc.) pode divergir do cadastro
 * já salvo do pet — o vet nunca escreve direto em `pets` a partir daqui. `reported_pet_data`
 * guarda esse relato à parte; só `POST /medical-records/{id}/apply-to-pet` (ação do TUTOR)
 * promove o que for aceito para `pets`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->jsonb('reported_pet_data')->nullable()->after('follow_up_appointment_id');
            $table->timestamp('reported_pet_data_applied_at')->nullable()->after('reported_pet_data');
            $table->foreignId('reported_pet_data_applied_by')
                ->nullable()
                ->after('reported_pet_data_applied_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reported_pet_data_applied_by');
            $table->dropColumn(['reported_pet_data', 'reported_pet_data_applied_at']);
        });
    }
};
