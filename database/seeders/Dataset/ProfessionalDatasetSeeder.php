<?php

namespace Database\Seeders\Dataset;

use App\Enums\ProfessionalType;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * ~1.000 profissionais de CADA um dos 7 tipos canônicos, todos internamente coerentes.
 *
 * Coerência é garantida por CONSTRUÇÃO, não por revisão: cada cadastro nasce de uma
 * `CoherentOffering` planejada pela matriz de domínio (`ProfessionalOfferingMatrix`), a mesma
 * que o cadastro real valida. Um "Hospital Veterinário Freitas" com tipo `grooming` e
 * serviços de clínica geral — o defeito que originou esta tarefa — é literalmente
 * inexprimível aqui.
 *
 * Escrita em lotes com o query builder, e não com Eloquent: 7 mil profissionais e ~40 mil
 * serviços via model significariam ~50 mil INSERTs individuais mais 7 mil hashes bcrypt. O
 * hash é computado UMA vez, fora daqui, e reutilizado.
 *
 * O e-mail é determinístico (`clinic0@2pets.dev`, `vet417@2pets.dev`) e é ele que reconstrói
 * a relação linha→id depois do INSERT em lote: o query builder do Laravel não tem
 * `RETURNING` em massa, e adivinhar a sequência do Postgres seria frágil.
 */
class ProfessionalDatasetSeeder extends Seeder
{
    public const PER_TYPE = 1_000;

    private const BATCH = 250;

    public function __construct(private readonly string $passwordHash) {}

    public function run(): void
    {
        $pool = BetaRegionGeography::weightedPool();

        foreach (ProfessionalType::cases() as $type) {
            $this->seedType($type, $pool);
            $this->command?->info(sprintf('  %-11s %d cadastros', $type->value, self::PER_TYPE));
        }
    }

    /**
     * @param  list<array{city: string, state: string, lat: float, lng: float, spread: float, weight: int, zip: string}>  $pool
     */
    private function seedType(ProfessionalType $type, array $pool): void
    {
        $roleId = $this->roleIdFor($type);

        for ($start = 0; $start < self::PER_TYPE; $start += self::BATCH) {
            $size = min(self::BATCH, self::PER_TYPE - $start);

            DB::transaction(fn () => $this->seedBatch($type, $pool, $roleId, $start, $size));
        }
    }

    /**
     * @param  list<array{city: string, state: string, lat: float, lng: float, spread: float, weight: int, zip: string}>  $pool
     */
    private function seedBatch(ProfessionalType $type, array $pool, int $roleId, int $start, int $size): void
    {
        $userRows = [];
        $plans = [];

        for ($offset = 0; $offset < $size; $offset++) {
            $businessName = ProfessionalNaming::businessNameFor($type);
            $displayName = ProfessionalNaming::displayNameFor($type, $businessName);

            $userRows[] = ProfessionalRowBuilder::userRow(
                $start + $offset, $type, $displayName, DatasetRandom::pick($pool), $this->passwordHash,
            );
            $plans[] = [
                'index' => self::globalSequence($type, $start + $offset),
                'business_name' => $businessName,
                'offering' => CoherentOfferingPlanner::planFor($type),
            ];
        }

        DB::table('users')->insert($userRows);

        $this->persistProfiles($type, $roleId, $userRows, $plans);
    }

    /**
     * @param  list<array<string, mixed>>  $userRows
     * @param  list<array{index: int, business_name: ?string, offering: CoherentOffering}>  $plans
     */
    private function persistProfiles(ProfessionalType $type, int $roleId, array $userRows, array $plans): void
    {
        $idsByEmail = $this->idsByEmail(array_column($userRows, 'email'));

        $professionalRows = [];
        $serviceRows = [];
        $userIds = [];

        foreach ($plans as $position => $plan) {
            $userId = $idsByEmail[$userRows[$position]['email']];
            $userIds[] = $userId;
            $professionalRows[] = ProfessionalRowBuilder::professionalRow(
                $userId, $plan['index'], $type, $plan['business_name'], $plan['offering'],
            );
            $serviceRows = [...$serviceRows, ...ProfessionalRowBuilder::serviceRows($userId, $plan['offering'])];
        }

        DB::table('professionals')->insert($professionalRows);
        DB::table('services')->insert($serviceRows);
        $this->assignRole($roleId, $userIds);
        $this->writeGeography($userIds);
    }

    /**
     * @param  list<string>  $emails
     * @return array<string, int>
     */
    private function idsByEmail(array $emails): array
    {
        return DB::table('users')
            ->whereIn('email', $emails)
            ->pluck('id', 'email')
            ->map(static fn (int|string $id): int => (int) $id)
            ->all();
    }

    /**
     * Índice único entre TODOS os tipos: o número dentro do tipo mais o deslocamento do
     * tipo. `professionals.cnpj` é único, e um contador que reinicia a cada tipo fazia a
     * primeira clínica colidir com o primeiro laboratório (23505 no meio do seed).
     */
    private static function globalSequence(ProfessionalType $type, int $indexWithinType): int
    {
        $ordinal = array_search($type, ProfessionalType::cases(), true);

        return (int) $ordinal * self::PER_TYPE + $indexWithinType;
    }

    /**
     * Sem papel Spatie a conta existe e é invisível para todo endpoint com `hasAnyRole()` —
     * o 403 silencioso já documentado em `agent-memory/.../fluxo-acesso-vet-pet.md`.
     * `users.role`/`user_type` não autorizam nada.
     */
    private function roleIdFor(ProfessionalType $type): int
    {
        return (int) DB::table('roles')->where('name', $type->defaultRoleName())->value('id');
    }

    /**
     * @param  list<int>  $userIds
     */
    private function assignRole(int $roleId, array $userIds): void
    {
        DB::table('model_has_roles')->insert(array_map(
            static fn (int $userId): array => [
                'role_id' => $roleId,
                'model_type' => User::class,
                'model_id' => $userId,
            ],
            $userIds,
        ));
    }

    /**
     * `location` (geography) só existe via SQL cru — `ST_MakePoint(longitude, latitude)`, com
     * a LONGITUDE primeiro. Roda na mesma transação do INSERT dos users, então nunca existe
     * uma janela em que latitude/longitude e `location` discordem. O array de ids vai como UM
     * binding (`::bigint[]`), nunca interpolado na string.
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
}
