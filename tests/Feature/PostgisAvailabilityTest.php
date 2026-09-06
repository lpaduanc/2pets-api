<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Prova de que a suíte roda mesmo contra PostgreSQL + PostGIS (Fase 1 do plano de
 * otimização), não contra o antigo sqlite `:memory:`.
 *
 * Todo migration de PostGIS/pg_trgm/unaccent do repo tem o guard
 * `if (DB::getDriverName() !== 'pgsql') return;`. No sqlite esse guard fazia a
 * migration virar no-op silencioso: a extensão nunca era criada e a coluna
 * `users.location` nunca existia, então nenhuma query geográfica — o core do
 * produto — era exercitada. Se `phpunit.xml`/`config/database.php` regredirem para
 * sqlite no futuro, este teste falha alto e cedo em vez de deixar a busca por
 * geolocalização voltar a rodar sem cobertura nenhuma.
 */
class PostgisAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_test_suite_runs_on_the_postgres_driver(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
    }

    public function test_the_postgis_extension_is_installed_and_reports_a_version(): void
    {
        $version = DB::selectOne('SELECT PostGIS_Version() AS version')->version;

        $this->assertNotEmpty($version);
    }

    public function test_the_users_location_column_exists_as_a_geography_type(): void
    {
        $column = DB::selectOne(
            "SELECT udt_name FROM information_schema.columns
             WHERE table_name = 'users' AND column_name = 'location'"
        );

        $this->assertNotNull(
            $column,
            'A coluna users.location não existe — a migration PostGIS não rodou (guard pgsql ativo em driver não-pgsql?).'
        );
        $this->assertSame('geography', $column->udt_name);
    }
}
