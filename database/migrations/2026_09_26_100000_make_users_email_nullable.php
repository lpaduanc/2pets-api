<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contrato `docs/atendimento-veterinario/07-contrato-agendamento-pet-novo.md` §2.
 *
 * E-mail do tutor passa a ser opcional: o agendamento de paciente novo (`professional/
 * appointments/new-patient`) precisa criar a conta do tutor só com CPF, sem exigir e-mail.
 *
 * Seguro sem mudança de índice: `users_email_unique` já é um índice único PARCIAL
 * (`WHERE deleted_at IS NULL`, ver `2026_09_16_090100_add_partial_unique_indexes_for_soft_deleted_documents`),
 * e o Postgres trata cada `NULL` como distinto num índice único — múltiplas linhas sem e-mail
 * convivem sem conflito, exatamente como múltiplos CPFs nulos já convivem hoje.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Backfill defensivo: reverter para NOT NULL com linha nula quebraria a migration.
        // Contas sem e-mail nascidas por este fluxo ficam com um placeholder identificável,
        // nunca um e-mail de terceiro.
        DB::table('users')
            ->whereNull('email')
            ->update(['email' => DB::raw("'sem-email-'||id||'@2pets.invalid'")]);

        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable(false)->change();
        });
    }
};
