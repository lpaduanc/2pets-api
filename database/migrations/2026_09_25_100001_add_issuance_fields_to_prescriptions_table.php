<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Imutabilidade + tipos de receituário — contrato
 * docs/atendimento-veterinario/03-contrato-receituario.md §2.
 *
 * `control_number`, `signature_type`, `signed_at`, `verification_code`, `hash` são
 * PREPARATÓRIAS para a fatia futura de assinatura digital/RCEV: criadas vazias agora para
 * evitar `ALTER TABLE` caro numa tabela populada depois. Nenhum código desta fatia lê ou
 * grava essas cinco colunas, e nenhum Resource as expõe (doc de domínio §4.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->string('standalone_reason')->nullable();

            $table->timestamp('issued_at')->nullable();

            $table->timestamp('canceled_at')->nullable();
            $table->text('canceled_reason')->nullable();
            $table->foreignId('canceled_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('supersedes_id')->nullable()->constrained('prescriptions')->nullOnDelete();

            $table->string('kind', 20)->default('simple');

            // Preparatórias — não lidas nesta fatia.
            $table->string('control_number')->nullable();
            $table->string('signature_type', 20)->default('none');
            $table->timestamp('signed_at')->nullable();
            $table->string('verification_code')->nullable()->unique();
            $table->string('hash')->nullable();

            // `MedicalRecordFinalizationService::finalize()` e o descarte de rascunho
            // (`MedicalRecordController::destroy()`) passam a filtrar
            // `medical_record_id = ? AND issued_at IS NULL` a cada chamada — sem índice,
            // isso varre a tabela inteira (500k linhas no benchmark).
            $table->index('medical_record_id');
            $table->index('issued_at');
        });
    }

    public function down(): void
    {
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('canceled_by');
            $table->dropConstrainedForeignId('supersedes_id');

            $table->dropIndex(['medical_record_id']);
            $table->dropIndex(['issued_at']);

            $table->dropColumn([
                'standalone_reason',
                'issued_at',
                'canceled_at',
                'canceled_reason',
                'kind',
                'control_number',
                'signature_type',
                'signed_at',
                'verification_code',
                'hash',
            ]);
        });
    }
};
