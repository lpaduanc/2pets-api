<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inverte quem decide o nível de acesso do veterinário ao pet: passa a ser o TUTOR, no aceite.
 *
 * O que o vet manda vira indicação de necessidade (`requested_access_level`); `access_level`
 * passa a significar exclusivamente "nível concedido pelo tutor" e por isso fica NULL enquanto
 * a solicitação está `pending`.
 *
 * Consequência estrutural: o vet precisa poder pedir upgrade (`read` aceito → pedir `full`),
 * então o índice parcial único antigo — que cobria `pending` E `accepted` no mesmo predicado —
 * bloqueava a solicitação nova. Ele vira DOIS índices parciais independentes: no máximo um
 * `pending` e no máximo um `accepted` por par (pet, vet).
 *
 * O upgrade aceito transiciona a linha antiga para `superseded` (terminal), nunca deleta e
 * nunca reescreve o nível anterior: `granted_at`/`superseded_at` + `superseded_by_id`
 * reconstroem "teve read de X até Y e full a partir de Y" (CLAUDE.md §5 e §6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pet_vet_accesses', function (Blueprint $table): void {
            $table->string('requested_access_level', 20)->nullable()->after('access_level');
            $table->timestamp('superseded_at')->nullable()->after('revoked_at');
            $table->foreignId('superseded_by_id')->nullable()->after('superseded_at')
                ->constrained('pet_vet_accesses')->nullOnDelete();
        });

        // Ordem importa: só depois de derrubar o NOT NULL dá para zerar o nível das linhas
        // que nunca tiveram concessão.
        $this->makeGrantedLevelNullable();
        $this->backfillRequestedLevel();
        $this->splitLiveUniqueIndex();
    }

    public function down(): void
    {
        $this->restoreLiveUniqueIndex();
        $this->restoreGrantedLevelNotNull();

        Schema::table('pet_vet_accesses', function (Blueprint $table): void {
            $table->dropForeign(['superseded_by_id']);
            $table->dropColumn(['requested_access_level', 'superseded_at', 'superseded_by_id']);
        });
    }

    /**
     * Linhas existentes: até aqui `access_level` guardava o que o VET pediu, porque era ele
     * quem escolhia. Copiamos para a coluna nova em todas as linhas — em `accepted`/`revoked`
     * pedido e concessão coincidiam sob a regra antiga.
     *
     * Em `pending` e `rejected` nenhum tutor concedeu nada, então `access_level` é zerado:
     * manter o valor ali passaria a mentir sob a semântica nova.
     */
    private function backfillRequestedLevel(): void
    {
        DB::table('pet_vet_accesses')->update([
            'requested_access_level' => DB::raw('access_level'),
        ]);

        DB::table('pet_vet_accesses')
            ->whereIn('status', ['pending', 'rejected'])
            ->update(['access_level' => null]);
    }

    /**
     * `access_level` passa a significar "nível concedido pelo tutor" e por isso precisa aceitar
     * NULL: enquanto `pending`, ninguém concedeu nada. O default `'read'` sai junto — ele era
     * justamente o que fazia o pedido do vet virar concessão silenciosa.
     *
     * Via `->change()` do schema builder, não `ALTER TABLE` cru, para não amarrar a migration
     * ao PostgreSQL.
     */
    private function makeGrantedLevelNullable(): void
    {
        Schema::table('pet_vet_accesses', function (Blueprint $table): void {
            $table->string('access_level', 20)->nullable()->change();
        });
    }

    private function restoreGrantedLevelNotNull(): void
    {
        DB::table('pet_vet_accesses')->whereNull('access_level')->update(['access_level' => 'read']);

        Schema::table('pet_vet_accesses', function (Blueprint $table): void {
            $table->string('access_level', 20)->default('read')->change();
        });
    }

    /**
     * `pet_vet_access_live_unique (pet_id, veterinarian_id) WHERE status IN ('pending','accepted')`
     * garantia demais: impedia a coexistência legítima de "acesso `read` já concedido" com
     * "solicitação de `full` aguardando o tutor". As duas garantias que continuam valendo são
     * independentes — um pendente e um aceito por par.
     */
    private function splitLiveUniqueIndex(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS pet_vet_access_live_unique');
        DB::statement(
            "CREATE UNIQUE INDEX pet_vet_access_pending_unique
             ON pet_vet_accesses (pet_id, veterinarian_id)
             WHERE status = 'pending'"
        );
        DB::statement(
            "CREATE UNIQUE INDEX pet_vet_access_accepted_unique
             ON pet_vet_accesses (pet_id, veterinarian_id)
             WHERE status = 'accepted'"
        );
    }

    /**
     * O predicado antigo é mais restritivo, então o rollback só é possível depois de resolver
     * os pedidos de upgrade em voo — pares com `pending` E `accepted` ao mesmo tempo não cabem
     * num índice único só. Eles viram `rejected` com motivo de sistema: a linha e sua trilha
     * ficam no banco (nada é deletado), apenas sai do conjunto "vivo".
     */
    private function rejectUpgradeRequestsInFlight(): void
    {
        DB::table('pet_vet_accesses as pending')
            ->where('pending.status', 'pending')
            ->whereExists(fn ($query) => $query->select(DB::raw(1))
                ->from('pet_vet_accesses as granted')
                ->whereColumn('granted.pet_id', 'pending.pet_id')
                ->whereColumn('granted.veterinarian_id', 'pending.veterinarian_id')
                ->where('granted.status', 'accepted'))
            ->update([
                'status' => 'rejected',
                'is_active' => false,
                'responded_at' => now(),
                'rejection_reason' => 'Solicitação de upgrade encerrada por reversão de schema.',
            ]);
    }

    private function restoreLiveUniqueIndex(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->rejectUpgradeRequestsInFlight();

        DB::statement('DROP INDEX IF EXISTS pet_vet_access_pending_unique');
        DB::statement('DROP INDEX IF EXISTS pet_vet_access_accepted_unique');
        DB::statement(
            "CREATE UNIQUE INDEX pet_vet_access_live_unique
             ON pet_vet_accesses (pet_id, veterinarian_id)
             WHERE status IN ('pending', 'accepted')"
        );
    }
};
