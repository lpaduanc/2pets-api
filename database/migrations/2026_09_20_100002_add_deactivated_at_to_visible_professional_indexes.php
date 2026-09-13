<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `User::scopeVisibleProfessional()` passou a excluir contas desativadas
 * (`whereNull('deactivated_at')`) — um profissional que se autodesativou não pode continuar
 * aparecendo como reservável na busca pública. Os dois índices parciais que servem essa busca
 * (`idx_users_visible_professional_location`/`_name`, criados em
 * `2026_09_06_000001_add_search_performance_indexes_to_users_table`) têm que ter o MESMO
 * predicado do scope, ou o Postgres para de casar o índice em silêncio — ver o aviso na
 * migration original e `ProfessionalVisibilityIndexTest`.
 *
 * `CONCURRENTLY` não roda dentro de transação; não existe `ALTER INDEX ... SET WHERE`, então a
 * única forma de trocar o predicado é DROP + CREATE.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_users_visible_professional_location');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_users_visible_professional_name');

        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_users_visible_professional_location
            ON users USING GIST (location)
            WHERE role = 'professional'
              AND profile_completed = true
              AND registration_status = 'approved'
              AND is_suspended = false
              AND deactivated_at IS NULL
              AND deleted_at IS NULL
            SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_users_visible_professional_name
            ON users USING BTREE (name)
            WHERE role = 'professional'
              AND profile_completed = true
              AND registration_status = 'approved'
              AND is_suspended = false
              AND deactivated_at IS NULL
              AND deleted_at IS NULL
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_users_visible_professional_location');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_users_visible_professional_name');

        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_users_visible_professional_location
            ON users USING GIST (location)
            WHERE role = 'professional'
              AND profile_completed = true
              AND registration_status = 'approved'
              AND is_suspended = false
              AND deleted_at IS NULL
            SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_users_visible_professional_name
            ON users USING BTREE (name)
            WHERE role = 'professional'
              AND profile_completed = true
              AND registration_status = 'approved'
              AND is_suspended = false
              AND deleted_at IS NULL
            SQL);
    }
};
