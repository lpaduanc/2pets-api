<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vínculo EXPLÍCITO profissional↔cliente.
 *
 * `ProfessionalClientController::index` até aqui derivava "cliente" só de quem tinha
 * appointment, invoice ou `PetVetAccess` ativo com o profissional — um cliente cadastrado
 * manualmente pela recepção (`ClientProvisioningService::provision`) não se encaixava em
 * nenhum dos três e nascia invisível na própria listagem que acabou de criá-lo.
 *
 * Índice único PARCIAL (só sobre vínculos vivos) em vez de `UNIQUE(professional_id,
 * client_id)` simples: um soft delete (desvincular) não pode bloquear um vínculo futuro com
 * o mesmo par, e soft delete é regra do projeto (CLAUDE.md §5) — nunca DELETE físico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('professional_clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('professional_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('client_id');
        });

        DB::statement(
            'CREATE UNIQUE INDEX professional_clients_live_unique
             ON professional_clients (professional_id, client_id)
             WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('professional_clients');
    }
};
