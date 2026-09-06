<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resolução CFMV 1.465/2023 — diferencia tipos de teleatendimento:
 *   - teletriagem: triagem pré-consulta, pode ser entre vet e tutor sem vínculo prévio.
 *   - teleconsulta: consulta propriamente dita, exige consulta presencial prévia nos últimos 180 dias.
 *   - teleinterconsulta: entre dois médicos-veterinários, não é com o tutor.
 *   - telemonitoramento: acompanhamento de pet já sob cuidado do profissional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_consultations', function (Blueprint $table) {
            $table->string('teleatendimento_type', 30)->default('teletriagem')->after('provider');
            $table->foreignId('previous_appointment_id')->nullable()->after('teleatendimento_type')->constrained('appointments')->nullOnDelete();
            $table->foreignId('vet_counterpart_id')->nullable()->after('previous_appointment_id')->constrained('users')->nullOnDelete();

            $table->index('teleatendimento_type');
        });
    }

    public function down(): void
    {
        Schema::table('video_consultations', function (Blueprint $table) {
            $table->dropForeign(['previous_appointment_id']);
            $table->dropForeign(['vet_counterpart_id']);
            $table->dropIndex(['teleatendimento_type']);
            $table->dropColumn(['teleatendimento_type', 'previous_appointment_id', 'vet_counterpart_id']);
        });
    }
};
