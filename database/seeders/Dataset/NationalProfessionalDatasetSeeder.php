<?php

namespace Database\Seeders\Dataset;

use App\Enums\ProfessionalType;
use App\Models\User;
use App\Support\Catalog\SpecialtyPivotBackfill;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * 100 mil profissionais COERENTES espalhados pelas 27 UFs, para teste de desempenho da busca.
 *
 *   docker compose exec api php artisan db:seed --class="Database\Seeders\Dataset\NationalProfessionalDatasetSeeder" --force
 *
 * ── Por que não o `BenchmarkSeeder` nem o `DevelopmentDatasetSeeder` ──────────────────
 * O benchmark tem volume, mas sorteia nome, tipo e serviço de forma independente (cadastro
 * que se contradiz) e só cobre SP. O dataset de desenvolvimento é coerente, mas fica na
 * região beta e começa com TRUNCATE de `users` — apagaria as contas criadas à mão. Este
 * reaproveita os builders coerentes do segundo com a geografia nacional, e só ACRESCENTA.
 *
 * ── Retomável ─────────────────────────────────────────────────────────────────────────
 * O e-mail é determinístico por tipo (`vet417@nacional.2pets.dev`); cada tipo recomeça de
 * quantos já existem. Rodar de novo depois de completo não insere nada, e uma execução
 * interrompida continua de onde parou em vez de duplicar.
 */
class NationalProfessionalDatasetSeeder extends Seeder
{
    public const TOTAL = 100_000;

    public const EMAIL_DOMAIN = 'nacional.2pets.dev';

    /** 500 profissionais por INSERT: ~12 mil bindings, abaixo do teto de 65.535 do Postgres. */
    private const BATCH = 500;

    private const RANDOM_SEED = 20261025;

    /**
     * `cnpj` é único e sai de `ProfessionalRowBuilder::syntheticCnpj($sequence)`. O
     * `ProfessionalDatasetSeeder` usa 0..6.999; começar em 1 milhão deixa os dois datasets
     * conviverem no mesmo banco sem 23505.
     */
    private const SEQUENCE_OFFSET = 1_000_000;

    private const TYPE_STRIDE = 100_000;

    private const POOL_TABLES = ['users', 'professionals', 'services'];

    private string $passwordHash;

    public function run(): void
    {
        $this->guardAgainstUnsafeEnvironment();
        DatasetRandom::seed(self::RANDOM_SEED);

        $startedAt = microtime(true);
        $this->passwordHash = Hash::make('password');
        $pool = NationalGeography::weightedPool();

        foreach (ProfessionalType::cases() as $ordinal => $type) {
            $this->seedType($type, $ordinal, $pool);
        }

        (new SpecialtyPivotBackfill)->run();
        $this->analyze();
        $this->command?->info(sprintf('NationalProfessionalDatasetSeeder concluído em %.1fs.', microtime(true) - $startedAt));
    }

    /**
     * Mesmo guard do `DevelopmentDatasetSeeder`: só `local`, só Postgres, nunca o `_test`.
     */
    private function guardAgainstUnsafeEnvironment(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('NationalProfessionalDatasetSeeder só roda com APP_ENV=local.');
        }

        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('NationalProfessionalDatasetSeeder requer PostgreSQL (geography/GIST).');
        }

        if (str_ends_with((string) DB::getDatabaseName(), '_test')) {
            throw new RuntimeException('Recusando semear o banco de testes.');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $pool
     */
    private function seedType(ProfessionalType $type, int $ordinal, array $pool): void
    {
        $wanted = self::quotaFor($ordinal);
        $existing = $this->existingCount($type);
        $roleId = (int) DB::table('roles')->where('name', $type->defaultRoleName())->value('id');

        for ($start = $existing; $start < $wanted; $start += self::BATCH) {
            $size = min(self::BATCH, $wanted - $start);
            DB::transaction(fn () => $this->seedBatch($type, $ordinal, $pool, $roleId, $start, $size));
        }

        $this->command?->info(sprintf('  %-11s %d cadastros (%d novos)', $type->value, $wanted, max(0, $wanted - $existing)));
    }

    /** Divide o total entre os 7 tipos; o resto da divisão vai para os primeiros. */
    private static function quotaFor(int $ordinal): int
    {
        $types = count(ProfessionalType::cases());

        return intdiv(self::TOTAL, $types) + ($ordinal < self::TOTAL % $types ? 1 : 0);
    }

    private function existingCount(ProfessionalType $type): int
    {
        return DB::table('users')
            ->where('email', 'like', $type->value.'%@'.self::EMAIL_DOMAIN)
            ->where('user_type', $type->value)
            ->count();
    }

    /**
     * @param  list<array<string, mixed>>  $pool
     */
    private function seedBatch(ProfessionalType $type, int $ordinal, array $pool, int $roleId, int $start, int $size): void
    {
        $userRows = [];
        $plans = [];

        for ($index = $start; $index < $start + $size; $index++) {
            $place = DatasetRandom::pick($pool);
            $businessName = ProfessionalNaming::businessNameFor($type);
            $userRows[] = $this->userRow($type, $index, $businessName, $place);
            $plans[] = [
                'sequence' => self::SEQUENCE_OFFSET + $ordinal * self::TYPE_STRIDE + $index,
                'business_name' => $businessName,
                'state' => $place['state'],
                'offering' => CoherentOfferingPlanner::planFor($type),
            ];
        }

        DB::table('users')->insert($userRows);
        $this->persistProfiles($type, $roleId, $userRows, $plans);
    }

    /**
     * O builder do dataset beta fixa domínio de e-mail e DDD 11; aqui os dois vêm da praça.
     *
     * @param  array<string, mixed>  $place
     * @return array<string, mixed>
     */
    private function userRow(ProfessionalType $type, int $index, ?string $businessName, array $place): array
    {
        $displayName = ProfessionalNaming::displayNameFor($type, $businessName);
        $row = ProfessionalRowBuilder::userRow($index, $type, $displayName, $place, $this->passwordHash);

        return [
            ...$row,
            'email' => sprintf('%s%d@%s', $type->value, $index, self::EMAIL_DOMAIN),
            'phone' => $place['ddd'].mt_rand(900000000, 999999999),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $userRows
     * @param  list<array{sequence: int, business_name: ?string, state: string, offering: CoherentOffering}>  $plans
     */
    private function persistProfiles(ProfessionalType $type, int $roleId, array $userRows, array $plans): void
    {
        $idsByEmail = DB::table('users')->whereIn('email', array_column($userRows, 'email'))->pluck('id', 'email');
        $professionalRows = [];
        $serviceRows = [];
        $userIds = [];

        foreach ($plans as $position => $plan) {
            $userId = (int) $idsByEmail[$userRows[$position]['email']];
            $userIds[] = $userId;
            $professionalRows[] = self::professionalRow($userId, $type, $plan);
            array_push($serviceRows, ...ProfessionalRowBuilder::serviceRows($userId, $plan['offering']));
        }

        DB::table('professionals')->insert($professionalRows);
        DB::table('services')->insert($serviceRows);
        $this->assignRole($roleId, $userIds);
        $this->writeGeography($userIds);
    }

    /**
     * CRMV é estadual: o builder grava SP para todos, aqui ele segue a UF do cadastro.
     *
     * @param  array{sequence: int, business_name: ?string, state: string, offering: CoherentOffering}  $plan
     * @return array<string, mixed>
     */
    private static function professionalRow(int $userId, ProfessionalType $type, array $plan): array
    {
        $row = ProfessionalRowBuilder::professionalRow($userId, $plan['sequence'], $type, $plan['business_name'], $plan['offering']);

        foreach (['crmv_state', 'technical_responsible_crmv_state'] as $column) {
            $row[$column] = $row[$column] === null ? null : $plan['state'];
        }

        return $row;
    }

    /**
     * Sem papel Spatie a conta é invisível para todo endpoint com `hasAnyRole()`.
     *
     * @param  list<int>  $userIds
     */
    private function assignRole(int $roleId, array $userIds): void
    {
        DB::table('model_has_roles')->insert(array_map(
            static fn (int $userId): array => ['role_id' => $roleId, 'model_type' => User::class, 'model_id' => $userId],
            $userIds,
        ));
    }

    /**
     * `location` (geography) só existe via SQL cru, LONGITUDE primeiro, na mesma transação do
     * INSERT — ver `ProfessionalDatasetSeeder::writeGeography()`.
     *
     * @param  list<int>  $userIds
     */
    private function writeGeography(array $userIds): void
    {
        DB::statement(
            'UPDATE users SET location = ST_SetSRID(ST_MakePoint(longitude, latitude), 4326)::geography
             WHERE id = ANY(?::bigint[])',
            ['{'.implode(',', $userIds).'}'],
        );
    }

    /** Sem ANALYZE o planner decide a busca com a estatística de quando havia 9 profissionais. */
    private function analyze(): void
    {
        foreach ([...self::POOL_TABLES, 'professional_specialty'] as $table) {
            DB::statement("ANALYZE {$table}");
        }
    }
}
