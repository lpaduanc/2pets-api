<?php

namespace Database\Seeders\Benchmark;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Gera volume para `locations` e `lost_pet_alerts` (Fase 6 do plano de
 * otimizacao de queries — Haversine cru para PostGIS).
 *
 * `BenchmarkSeeder` (Fase 2) NAO popula essas duas tabelas — ver
 * `.claude/agent-memory/backend-specialist/indices-fase4.md` ("nota sobre
 * volume" da Fase 6). Sem volume, `EXPLAIN` mostra `Seq Scan` mesmo com o
 * indice GIST criado, e corretamente: o planner prefere seq scan numa
 * tabela de poucas paginas.
 *
 * Mesma tecnica do `BenchmarkSeeder`: `INSERT ... SELECT FROM
 * generate_series(...)` em SQL cru (nao ha factory para `Location`/
 * `LostPetAlert`), distribuicao geografica clusterizada reaproveitando os
 * centroides de `NamePools`, e o cuidado com o hoisting de `random()` em
 * `CROSS JOIN LATERAL` sem correlacao (ver `postgis.md` — PG 16.4 avalia
 * `random()` uma unica vez para a query inteira quando o LATERAL nao
 * referencia nenhuma coluna do lado esquerdo do JOIN).
 *
 * NAO e registrado em `DatabaseSeeder` de proposito — invocado explicitamente:
 *
 *   docker compose exec backend php artisan db:seed --class="Database\Seeders\Benchmark\GeoBenchmarkSeeder"
 *
 * Pressupoe que `BenchmarkSeeder` ja rodou (precisa de `users`/`professionals`/
 * `pets` com volume para referenciar via FK).
 */
class GeoBenchmarkSeeder extends Seeder
{
    private const TOTAL_LOCATIONS = 50_000;

    private const TOTAL_LOST_PET_ALERTS = 20_000;

    /** ~10% das coordenadas ficam fora dos clusters, mesmo padrao do BenchmarkSeeder. */
    private const OUTLIER_RATIO = 0.10;

    public function run(): void
    {
        $this->guardAgainstUnsafeEnvironment();

        $startedAt = microtime(true);

        $this->seedLocations();
        $this->seedLostPetAlerts();
        $this->vacuumAnalyze();

        $elapsedSeconds = round(microtime(true) - $startedAt, 1);
        $this->command?->info("GeoBenchmarkSeeder concluido em {$elapsedSeconds}s.");
    }

    private function guardAgainstUnsafeEnvironment(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException(
                'GeoBenchmarkSeeder só pode rodar em ambiente local (APP_ENV=local).'
            );
        }

        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'GeoBenchmarkSeeder requer PostgreSQL — gera geography/GIST e SQL exclusivo do driver.'
            );
        }

        $professionalCount = (int) DB::selectOne(
            "SELECT COUNT(*) AS c FROM users WHERE role = 'professional'"
        )->c;

        if ($professionalCount === 0) {
            throw new RuntimeException(
                'Nenhum professional encontrado — rode o BenchmarkSeeder antes.'
            );
        }

        $petCount = (int) DB::selectOne('SELECT COUNT(*) AS c FROM pets')->c;

        if ($petCount === 0) {
            throw new RuntimeException(
                'Nenhum pet encontrado — rode o BenchmarkSeeder antes.'
            );
        }
    }

    /**
     * 50 mil locations (clinicas/petshops podem ter mais de uma unidade —
     * ~1,1 por profissional no volume padrao do BenchmarkSeeder e realista).
     * `location` (geography) e calculada e gravada na MESMA statement do
     * INSERT, nunca num UPDATE depois — mesmo cuidado do `BenchmarkSeeder`.
     */
    private function seedLocations(): void
    {
        $this->runStep('pool: geo_professional_pool', <<<'SQL'
            CREATE TEMP TABLE geo_professional_pool AS
            SELECT row_number() OVER (ORDER BY id) AS rn, id
            FROM users WHERE role = 'professional';
            SQL);
        $this->runStep('index: geo_professional_pool', 'CREATE INDEX ON geo_professional_pool (rn);');

        $professionalCount = (int) DB::selectOne('SELECT COUNT(*) AS c FROM geo_professional_pool')->c;
        $lastNames = NamePools::toTextArrayLiteral(NamePools::LAST_NAMES);
        $centroidLatitudes = NamePools::toFloatArrayLiteral(NamePools::CENTROID_LATITUDES);
        $centroidLongitudes = NamePools::toFloatArrayLiteral(NamePools::CENTROID_LONGITUDES);
        $centroidCount = count(NamePools::CENTROID_LATITUDES);
        $lastSeq = self::TOTAL_LOCATIONS - 1;
        $outlierRatio = self::OUTLIER_RATIO;

        $sql = <<<SQL
            INSERT INTO locations (
                professional_id, name, address, city, state, zip_code,
                latitude, longitude, location, phone, email,
                opening_hours, working_days, is_primary, is_active,
                amenities, notes, created_at, updated_at
            )
            SELECT
                pool.id,
                'Unidade ' || (s + 1)::text,
                'Rua ' || names.ln[1 + (s % 50)] || ', ' || (100 + (s % 900))::text,
                (ARRAY['São Paulo','Guarulhos','Osasco','Santo André','Campinas']::text[])[1 + (s % 5)],
                'SP',
                lpad((s % 99999)::text, 5, '0') || '-000',
                pt.lat,
                pt.lng,
                ST_SetSRID(ST_MakePoint(pt.lng, pt.lat), 4326)::geography,
                '11' || lpad(((900000000 + s) % 999999999)::text, 9, '0'),
                'unidade' || s::text || '@2pets.test',
                '{"mon":{"open":"08:00","close":"18:00"}}'::json,
                '["mon","tue","wed","thu","fri"]'::json,
                (s % 5) = 0,
                (s % 100) < 90,
                '[]'::json,
                'Local gerado pelo GeoBenchmarkSeeder para testes de carga.',
                now(),
                now()
            FROM generate_series(0, {$lastSeq}) AS s
            JOIN geo_professional_pool pool ON pool.rn = (s % {$professionalCount}) + 1
            CROSS JOIN LATERAL (SELECT {$lastNames} AS ln) names
            CROSS JOIN LATERAL (
                -- "+ (s * 0)" forca avaliacao por linha (ver postgis.md sobre o
                -- hoisting de random() em LATERAL sem correlacao real no PG 16.4).
                SELECT
                    random() + (s * 0) AS r_outlier,
                    random() + (s * 0) AS r1,
                    random() + (s * 0) AS r2,
                    random() + (s * 0) AS r3
            ) rnd
            CROSS JOIN LATERAL (
                SELECT
                    CASE WHEN rnd.r_outlier < {$outlierRatio}
                        THEN -25.30 + rnd.r1 * (25.30 - 19.80)
                        ELSE ({$centroidLatitudes})[1 + (s % {$centroidCount})]
                             + (rnd.r1 + rnd.r2 + rnd.r3 - 1.5) * 0.05
                    END AS lat,
                    CASE WHEN rnd.r_outlier < {$outlierRatio}
                        THEN -53.11 + rnd.r2 * (53.11 - 44.16)
                        ELSE ({$centroidLongitudes})[1 + (s % {$centroidCount})]
                             + (rnd.r1 + rnd.r2 + rnd.r3 - 1.5) * 0.05
                    END AS lng
            ) pt;
            SQL;

        $this->runStep('locations (50k)', $sql);
    }

    /**
     * 20 mil lost_pet_alerts. Status distribuido (20% active / 60% found /
     * 20% cancelled) para o indice GIST PARCIAL (`WHERE status = 'active'`)
     * guardar so uma fatia da tabela, igual ao cenario real.
     */
    private function seedLostPetAlerts(): void
    {
        $this->runStep('pool: geo_pet_pool', <<<'SQL'
            CREATE TEMP TABLE geo_pet_pool AS
            SELECT row_number() OVER (ORDER BY id) AS rn, id AS pet_id, user_id
            FROM pets WHERE deleted_at IS NULL;
            SQL);
        $this->runStep('index: geo_pet_pool', 'CREATE INDEX ON geo_pet_pool (rn);');

        $petPoolCount = (int) DB::selectOne('SELECT COUNT(*) AS c FROM geo_pet_pool')->c;
        $centroidLatitudes = NamePools::toFloatArrayLiteral(NamePools::CENTROID_LATITUDES);
        $centroidLongitudes = NamePools::toFloatArrayLiteral(NamePools::CENTROID_LONGITUDES);
        $centroidCount = count(NamePools::CENTROID_LATITUDES);
        $lastSeq = self::TOTAL_LOST_PET_ALERTS - 1;
        $outlierRatio = self::OUTLIER_RATIO;

        $sql = <<<SQL
            INSERT INTO lost_pet_alerts (
                pet_id, user_id, status, description, last_seen_location,
                last_seen_latitude, last_seen_longitude, last_seen_geo,
                alert_radius_km, last_seen_at, contact_info, photos,
                reward_amount, views_count, shares_count, created_at, updated_at
            )
            SELECT
                pool.pet_id,
                pool.user_id,
                CASE
                    WHEN (s % 100) < 20 THEN 'active'
                    WHEN (s % 100) < 80 THEN 'found'
                    ELSE 'cancelled'
                END,
                'Alerta gerado pelo GeoBenchmarkSeeder para testes de carga.',
                'Endereço gerado por seeder de benchmark',
                pt.lat,
                pt.lng,
                ST_SetSRID(ST_MakePoint(pt.lng, pt.lat), 4326)::geography,
                round((1 + random() * 14)::numeric, 2),
                now() - (s % 1000) * INTERVAL '1 hour',
                '{"phone":"11999999999"}'::json,
                '[]'::json,
                NULL,
                (s % 500),
                (s % 50),
                now(),
                now()
            FROM generate_series(0, {$lastSeq}) AS s
            JOIN geo_pet_pool pool ON pool.rn = (s % {$petPoolCount}) + 1
            CROSS JOIN LATERAL (
                SELECT
                    random() + (s * 0) AS r_outlier,
                    random() + (s * 0) AS r1,
                    random() + (s * 0) AS r2,
                    random() + (s * 0) AS r3
            ) rnd
            CROSS JOIN LATERAL (
                SELECT
                    CASE WHEN rnd.r_outlier < {$outlierRatio}
                        THEN -25.30 + rnd.r1 * (25.30 - 19.80)
                        ELSE ({$centroidLatitudes})[1 + (s % {$centroidCount})]
                             + (rnd.r1 + rnd.r2 + rnd.r3 - 1.5) * 0.05
                    END AS lat,
                    CASE WHEN rnd.r_outlier < {$outlierRatio}
                        THEN -53.11 + rnd.r2 * (53.11 - 44.16)
                        ELSE ({$centroidLongitudes})[1 + (s % {$centroidCount})]
                             + (rnd.r1 + rnd.r2 + rnd.r3 - 1.5) * 0.05
                    END AS lng
            ) pt;
            SQL;

        $this->runStep('lost_pet_alerts (20k)', $sql);
    }

    private function vacuumAnalyze(): void
    {
        $this->runStep('desliga vacuum paralelo', 'SET max_parallel_maintenance_workers = 0;');
        $this->runStep('VACUUM ANALYZE locations', 'VACUUM ANALYZE locations;');
        $this->runStep('VACUUM ANALYZE lost_pet_alerts', 'VACUUM ANALYZE lost_pet_alerts;');
    }

    /**
     * @param  array<int, mixed>  $bindings
     */
    private function runStep(string $label, string $sql, array $bindings = []): void
    {
        $startedAt = microtime(true);

        DB::statement($sql, $bindings);

        $elapsedSeconds = round(microtime(true) - $startedAt, 2);
        $this->command?->info("  [{$elapsedSeconds}s] {$label}");
    }
}
