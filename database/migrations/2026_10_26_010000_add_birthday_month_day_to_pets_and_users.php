<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contrato `docs/gap-simplesvet/specs/25-paineis-operacionais-spec.md`, regra de negócio 3:
 * aniversariante que só informou dia/mês (sem ano completo) precisa de campo próprio — `pets`/
 * `users` só têm `birth_date` completo hoje.
 *
 * Índices funcionais em SQL cru: guardado por driver (`DB::getDriverName() !== 'pgsql'`) para
 * não derrubar a suíte de teste, que roda em sqlite (armadilha conhecida do projeto).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pets', function (Blueprint $table): void {
            $table->smallInteger('birthday_month')->nullable()->after('birth_date');
            $table->smallInteger('birthday_day')->nullable()->after('birthday_month');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->smallInteger('birthday_month')->nullable()->after('birth_date');
            $table->smallInteger('birthday_day')->nullable()->after('birthday_month');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Consulta de aniversário usa EXTRACT sobre `birth_date` quando a data completa
        // existe (índice funcional dedicado), e as colunas novas como fallback — sem os dois
        // índices, a query varre a tabela inteira toda vez que o painel abre.
        DB::statement(
            'CREATE INDEX pets_birthday_md_idx ON pets (EXTRACT(MONTH FROM birth_date), EXTRACT(DAY FROM birth_date)) '.
            'WHERE birth_date IS NOT NULL'
        );
        DB::statement(
            'CREATE INDEX pets_birthday_md_fallback_idx ON pets (birthday_month, birthday_day) '.
            'WHERE birth_date IS NULL AND birthday_month IS NOT NULL'
        );
        DB::statement(
            'CREATE INDEX users_birthday_md_idx ON users (EXTRACT(MONTH FROM birth_date), EXTRACT(DAY FROM birth_date)) '.
            'WHERE birth_date IS NOT NULL'
        );
        DB::statement(
            'CREATE INDEX users_birthday_md_fallback_idx ON users (birthday_month, birthday_day) '.
            'WHERE birth_date IS NULL AND birthday_month IS NOT NULL'
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS pets_birthday_md_idx');
            DB::statement('DROP INDEX IF EXISTS pets_birthday_md_fallback_idx');
            DB::statement('DROP INDEX IF EXISTS users_birthday_md_idx');
            DB::statement('DROP INDEX IF EXISTS users_birthday_md_fallback_idx');
        }

        Schema::table('pets', function (Blueprint $table): void {
            $table->dropColumn(['birthday_month', 'birthday_day']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['birthday_month', 'birthday_day']);
        });
    }
};
