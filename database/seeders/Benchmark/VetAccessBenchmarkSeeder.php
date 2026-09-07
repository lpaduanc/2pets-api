<?php

namespace Database\Seeders\Benchmark;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Volume para medir o fluxo "veterinário ↔ pet" (`pet_vet_accesses`), que o
 * `BenchmarkSeeder` não cobre.
 *
 *   docker compose exec backend php artisan db:seed --class="Database\Seeders\Benchmark\VetAccessBenchmarkSeeder"
 *
 * Depende do `BenchmarkSeeder` ter rodado antes (precisa dos pets e dos profissionais).
 *
 * Distribuição proposital em duas ondas:
 *   1. Cauda longa — cada pet ganha exatamente um acesso, distribuído entre 3 mil vets
 *      (~100 pacientes por vet). É o caso comum.
 *   2. Um "vet de volume" (o primeiro profissional) recebe dezenas de milhares de pacientes.
 *      É o caso que quebra busca client-side e paginação ingênua, e o único em que dá para
 *      ver se o índice da carteira de pacientes está sendo usado.
 *
 * A unicidade exigida pelo índice parcial `pet_access_live_unique` é garantida por construção:
 * na onda 1 cada pet aparece uma única vez; na onda 2 pulamos os pets que já caíram no vet de
 * volume (`(rn - 1) % VETS <> 0`).
 */
class VetAccessBenchmarkSeeder extends Seeder
{
    private const VETS = 3_000;

    private const HIGH_VOLUME_VET_PATIENTS = 50_000;

    public function run(): void
    {
        $this->guardAgainstUnsafeEnvironment();

        $this->createVetPool();
        $this->seedLongTailAccesses();
        $this->seedHighVolumeVetAccesses();
        $this->analyze();

        $this->command?->info('VetAccessBenchmarkSeeder concluido.');
    }

    private function guardAgainstUnsafeEnvironment(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('VetAccessBenchmarkSeeder só pode rodar em ambiente local (APP_ENV=local).');
        }

        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('VetAccessBenchmarkSeeder requer PostgreSQL.');
        }
    }

    private function createVetPool(): void
    {
        $vets = self::VETS;

        DB::statement(<<<SQL
            CREATE TEMP TABLE bench_vet_pool AS
            SELECT row_number() OVER (ORDER BY id) AS rn, id
            FROM users
            WHERE role = 'professional'
            ORDER BY id
            LIMIT {$vets};
            SQL);

        DB::statement('CREATE INDEX ON bench_vet_pool (rn);');

        // Só pets de tutores gerados pelo BenchmarkSeeder. Sem este recorte, o volume cai em
        // cima das contas de demonstração (`DemoDataSeeder`/`ProfessionalSeeder`) e o fluxo
        // manual de "solicitar acesso" nasce com todos os pets já concedidos — foi exatamente
        // o que confundiu o teste do usuário na primeira rodada.
        DB::statement(<<<'SQL'
            CREATE TEMP TABLE bench_access_pets AS
            SELECT row_number() OVER (ORDER BY p.id) AS rn, p.id AS pet_id, p.user_id AS tutor_id
            FROM pets p
            JOIN users u ON u.id = p.user_id
            WHERE p.deleted_at IS NULL
              AND u.email LIKE 'bench!_%' ESCAPE '!';
            SQL);

        DB::statement('CREATE INDEX ON bench_access_pets (rn);');
    }

    private function seedLongTailAccesses(): void
    {
        $vets = self::VETS;

        DB::statement(<<<SQL
            INSERT INTO pet_vet_accesses (
                pet_id, veterinarian_id, granted_by, access_level, status,
                is_active, requested_at, granted_at, created_at, updated_at
            )
            SELECT
                p.pet_id,
                v.id,
                p.tutor_id,
                'read',
                'accepted',
                true,
                now() - (p.rn % 900) * INTERVAL '1 day',
                now() - (p.rn % 900) * INTERVAL '1 day',
                now(),
                now()
            FROM bench_access_pets p
            JOIN bench_vet_pool v ON v.rn = ((p.rn - 1) % {$vets}) + 1
            ON CONFLICT DO NOTHING;
            SQL);
    }

    private function seedHighVolumeVetAccesses(): void
    {
        $vets = self::VETS;
        $patients = self::HIGH_VOLUME_VET_PATIENTS;

        DB::statement(<<<SQL
            INSERT INTO pet_vet_accesses (
                pet_id, veterinarian_id, granted_by, access_level, status,
                is_active, requested_at, granted_at, created_at, updated_at
            )
            SELECT
                p.pet_id,
                (SELECT id FROM bench_vet_pool WHERE rn = 1),
                p.tutor_id,
                'read',
                'accepted',
                true,
                now() - (p.rn % 900) * INTERVAL '1 day',
                now() - (p.rn % 900) * INTERVAL '1 day',
                now(),
                now()
            FROM (
                SELECT * FROM bench_access_pets
                WHERE ((rn - 1) % {$vets}) <> 0
                LIMIT {$patients}
            ) p
            ON CONFLICT DO NOTHING;
            SQL);
    }

    private function analyze(): void
    {
        DB::statement('ANALYZE pet_vet_accesses;');
    }
}
