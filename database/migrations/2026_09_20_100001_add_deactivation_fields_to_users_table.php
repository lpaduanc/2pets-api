<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Desativação de conta (decisão do dono do produto, 2026-09-13): a pessoa para de usar o
 * 2pets, mas o cadastro inteiro continua na base como lead recuperável — nunca um
 * `forceDelete`, nunca um apagamento de campo. Ver `AccountDeactivationService`.
 *
 * Deliberadamente colunas dedicadas, não um `account_status` armazenado: `is_suspended`
 * (punitiva) e `registration_status` já são eixos próprios e independentes, usados em dezenas
 * de call sites (`scopeVisibleProfessional`, agregados do admin, filtros). Um enum de estado
 * único que colapsasse os três exigiria migrar todos esses pontos e arriscaria reintroduzir a
 * mistura com `is_suspended` que o dono pediu para evitar. `App\Enums\AccountStatus` deriva um
 * rótulo único (ativa|desativada|suspensa) desses campos SOMENTE para leitura/exibição — nunca
 * é a fonte de verdade gravada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // NULL = conta ativa. Preenchida = desde quando está desativada. É a fonte de
            // verdade única para "esta conta está desativada" (ver `User::isDeactivated()`).
            $table->timestamp('deactivated_at')->nullable()->after('is_suspended');

            // Enum fechado (`App\Enums\DeactivationReason`) + nota livre — insumo da campanha
            // de reativação. Nunca preenchidos fora de uma desativação em curso.
            $table->string('deactivation_reason', 30)->nullable()->after('deactivated_at');
            $table->text('deactivation_note')->nullable()->after('deactivation_reason');

            // Quem desativou: a própria pessoa (autosserviço) ou um admin agindo por ela.
            // Distingue "saiu sozinho" de "admin desativou em nome dela" no painel.
            $table->foreignId('deactivated_by')->nullable()->after('deactivation_note')
                ->constrained('users')->nullOnDelete();

            // Último instante em que a conta voltou de uma desativação — métrica de campanha,
            // não afeta nenhuma regra de acesso.
            $table->timestamp('reactivated_at')->nullable()->after('deactivated_by');
        });

        // Filtro de admin (`?account_status=deactivated`) e predicado do índice de
        // visibilidade pública (ver migration seguinte) — poucas linhas, mas evita seq scan
        // conforme a base cresce.
        Schema::table('users', function (Blueprint $table) {
            $table->index('deactivated_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['deactivated_at']);
            $table->dropConstrainedForeignId('deactivated_by');
            $table->dropColumn(['deactivated_at', 'deactivation_reason', 'deactivation_note', 'reactivated_at']);
        });
    }
};
