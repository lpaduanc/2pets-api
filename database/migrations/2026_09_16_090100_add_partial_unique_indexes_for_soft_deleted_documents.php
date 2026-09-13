<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `users.email`/`users.cpf` e `professionals.cnpj`/`professionals.crmv` tinham índice único
 * PLANO — inclui linha soft-deletada. Efeito real: soft-deletar um usuário (LGPD, revogação
 * de acesso) bloqueia o e-mail/CPF dele para sempre, porque nenhum cadastro novo consegue
 * reusar o mesmo documento enquanto a linha antiga existir (mesmo invisível). `User` e
 * `Professional` (desde a migration anterior desta onda) usam `SoftDeletes` — o índice
 * precisa saber disso.
 *
 * Substituído por índice único PARCIAL (`WHERE deleted_at IS NULL`): dois registros ativos
 * nunca dividem o mesmo documento, mas um documento fica livre assim que o dono é
 * soft-deletado. É estritamente mais permissivo que o índice antigo — nunca cria colisão
 * onde não havia antes, só destrava o que já deveria estar livre.
 *
 * Mantém o MESMO nome de constraint (`users_email_unique`, etc.) de propósito: é o nome que
 * `DuplicateRegistrationException::fromQueryException()` casa contra a mensagem da
 * `QueryException` — trocar o nome quebraria esse mapeamento silenciosamente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_email_unique');
            $table->dropUnique('users_cpf_unique');
        });

        DB::statement('CREATE UNIQUE INDEX users_email_unique ON users (email) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX users_cpf_unique ON users (cpf) WHERE deleted_at IS NULL');

        Schema::table('professionals', function (Blueprint $table) {
            $table->dropUnique('professionals_cnpj_unique');
            $table->dropUnique('professionals_crmv_unique');
        });

        DB::statement('CREATE UNIQUE INDEX professionals_cnpj_unique ON professionals (cnpj) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX professionals_crmv_unique ON professionals (crmv) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS users_email_unique');
        DB::statement('DROP INDEX IF EXISTS users_cpf_unique');

        Schema::table('users', function (Blueprint $table) {
            $table->unique('email');
            $table->unique('cpf');
        });

        DB::statement('DROP INDEX IF EXISTS professionals_cnpj_unique');
        DB::statement('DROP INDEX IF EXISTS professionals_crmv_unique');

        Schema::table('professionals', function (Blueprint $table) {
            $table->unique('cnpj');
            $table->unique('crmv');
        });
    }
};
