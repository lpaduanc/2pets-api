<?php

namespace Database\Seeders\Dataset;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * ~1.000 tutores, cada um com 1 a 3 pets coerentes.
 *
 * Coerência de pet aqui significa: a raça pertence à espécie, e o porte e o peso vêm do
 * `size_category` da própria raça em `breeds` — um Yorkshire de 32 kg é o mesmo tipo de dado
 * absurdo que o "hospital de banho e tosa", só que no outro lado da plataforma.
 */
class TutorDatasetSeeder extends Seeder
{
    public const TOTAL = 1_000;

    private const BATCH = 250;

    public function __construct(private readonly string $passwordHash) {}

    public function run(): void
    {
        $pool = BetaRegionGeography::weightedPool();
        $breeds = $this->breedsBySpecies();
        $roleId = (int) DB::table('roles')->where('name', 'tutor')->value('id');

        for ($start = 0; $start < self::TOTAL; $start += self::BATCH) {
            $size = min(self::BATCH, self::TOTAL - $start);

            DB::transaction(fn () => $this->seedBatch($pool, $breeds, $roleId, $start, $size));
        }

        $this->command?->info('  tutores:    '.self::TOTAL.' cadastros com pets.');
    }

    /**
     * @param  list<array{city: string, state: string, lat: float, lng: float, spread: float, weight: int, zip: string}>  $pool
     * @param  array<string, list<array{id: int, name: string, size_category: ?string}>>  $breeds
     */
    private function seedBatch(array $pool, array $breeds, int $roleId, int $start, int $size): void
    {
        $userRows = [];

        for ($offset = 0; $offset < $size; $offset++) {
            $userRows[] = $this->tutorRow($start + $offset, DatasetRandom::pick($pool));
        }

        DB::table('users')->insert($userRows);

        $ids = DB::table('users')
            ->whereIn('email', array_column($userRows, 'email'))
            ->pluck('id')
            ->map(static fn (int|string $id): int => (int) $id)
            ->all();

        $this->assignRole($roleId, $ids);
        $this->seedPets($ids, $breeds);
        $this->writeGeography($ids);
    }

    /**
     * @param  array{city: string, state: string, lat: float, lng: float, spread: float, weight: int, zip: string}  $place
     * @return array<string, mixed>
     */
    private function tutorRow(int $sequence, array $place): array
    {
        $now = now();

        return [
            'name' => ProfessionalRowBuilder::personName(),
            'email' => sprintf('tutor%d@2pets.dev', $sequence),
            'password' => $this->passwordHash,
            'role' => 'tutor',
            'user_type' => 'tutor',
            'phone' => '11'.mt_rand(900000000, 999999999),
            'city' => $place['city'],
            'state' => $place['state'],
            'zip_code' => $place['zip'].'-'.str_pad((string) mt_rand(0, 999), 3, '0', STR_PAD_LEFT),
            'latitude' => round($place['lat'] + DatasetRandom::jitter($place['spread']), 8),
            'longitude' => round($place['lng'] + DatasetRandom::jitter($place['spread']), 8),
            'email_verified_at' => $now,
            'email_verified' => true,
            'profile_completed' => true,
            'registration_status' => 'approved',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @param  list<int>  $tutorIds
     * @param  array<string, list<array{id: int, name: string, size_category: ?string}>>  $breeds
     */
    private function seedPets(array $tutorIds, array $breeds): void
    {
        $rows = [];

        foreach ($tutorIds as $tutorId) {
            foreach (range(1, mt_rand(1, 3)) as $ignored) {
                $rows[] = PetRowBuilder::build($tutorId, $breeds);
            }
        }

        DB::table('pets')->insert($rows);
    }

    /**
     * @return array<string, list<array{id: int, name: string, size_category: ?string}>>
     */
    private function breedsBySpecies(): array
    {
        return DB::table('breeds')
            ->select('id', 'species', 'name', 'size_category')
            ->get()
            ->groupBy('species')
            ->map(fn ($group): array => $group->map(fn ($breed): array => [
                'id' => (int) $breed->id,
                'name' => $breed->name,
                'size_category' => $breed->size_category,
            ])->all())
            ->all();
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
     * Tutor também tem `location`: é a coordenada de origem da busca por proximidade quando
     * o navegador não dá permissão de geolocalização. Mesma regra de sempre — SQL cru,
     * `ST_MakePoint(longitude, latitude)`, longitude primeiro.
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
