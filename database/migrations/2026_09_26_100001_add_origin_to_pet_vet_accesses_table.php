<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contrato `docs/atendimento-veterinario/07-contrato-agendamento-pet-novo.md` §5.
 *
 * O grant automático concedido ao criar um "paciente novo" precisa ser identificável em
 * lote (auditoria futura, eventual revisão de política) sem depender de inferência — daí uma
 * coluna própria em vez de reaproveitar `granted_by == veterinarian_id` como sinal indireto
 * (isso também aconteceria, por acidente, em qualquer correção manual de dado).
 *
 * Default `tutor_authorization` preserva a leitura de toda linha já existente: até hoje, todo
 * `PetVetAccess` nasceu de uma solicitação respondida pelo tutor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pet_vet_accesses', function (Blueprint $table): void {
            $table->string('origin', 30)->default('tutor_authorization')->after('granted_by');
            $table->index('origin');
        });
    }

    public function down(): void
    {
        Schema::table('pet_vet_accesses', function (Blueprint $table): void {
            $table->dropIndex(['origin']);
            $table->dropColumn('origin');
        });
    }
};
