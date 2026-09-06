<?php

namespace Tests\Feature;

use App\Models\Pet;
use App\Models\PetDeworming;
use App\Models\User;
use App\Models\Vaccination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/pets/health-summary — the aggregated roll-up that replaced the 2xN
 * per-pet requests of the tutor health dashboard.
 */
class PetHealthSummaryTest extends TestCase
{
    use RefreshDatabase;

    /** pets + constrained eager load of vaccinations + of dewormings. Nothing else. */
    private const EXPECTED_QUERY_COUNT = 3;

    private User $tutor;

    /** `vaccinations.professional_id` is NOT NULL in the schema, so every fixture needs one. */
    private User $veterinarian;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tutor = User::factory()->tutor()->create();
        $this->veterinarian = User::factory()->veterinarian()->create();
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/pets/health-summary')->assertStatus(401);
    }

    public function test_it_returns_only_the_pets_of_the_authenticated_tutor(): void
    {
        $ownPet = Pet::factory()->create(['user_id' => $this->tutor->id, 'name' => 'Rex']);
        $strangerPet = Pet::factory()->create([
            'user_id' => User::factory()->tutor()->create()->id,
            'name' => 'Fiona',
        ]);

        $this->vaccinationDueIn($ownPet, 10);
        $this->vaccinationDueIn($strangerPet, 10);

        Sanctum::actingAs($this->tutor);

        $this->getJson('/api/pets/health-summary')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownPet->id)
            ->assertJsonPath('data.0.name', 'Rex')
            ->assertJsonMissing(['name' => 'Fiona']);
    }

    public function test_it_classifies_events_by_urgency_and_totals_them(): void
    {
        $pet = Pet::factory()->create(['user_id' => $this->tutor->id]);
        $this->vaccinationDueIn($pet, -5, 'V10');
        $this->dewormingDueIn($pet, 7, 'Drontal');

        Sanctum::actingAs($this->tutor);

        $response = $this->getJson('/api/pets/health-summary')->assertOk();

        $response->assertJsonPath('meta.overdue_count', 1)
            ->assertJsonPath('meta.due_soon_count', 1)
            ->assertJsonPath('meta.pets_count', 1)
            ->assertJsonPath('meta.window_days', 90)
            ->assertJsonPath('data.0.overdue_count', 1)
            ->assertJsonPath('data.0.due_soon_count', 1);

        // Events come sorted by due date: the overdue vaccine before the deworming.
        $response->assertJsonPath('data.0.events.0.type', 'vaccination')
            ->assertJsonPath('data.0.events.0.level', 'overdue')
            ->assertJsonPath('data.0.events.0.reference', 'V10')
            ->assertJsonPath('data.0.events.0.days_until', -5)
            ->assertJsonPath('data.0.events.1.type', 'deworming')
            ->assertJsonPath('data.0.events.1.level', 'due_soon')
            ->assertJsonPath('data.0.events.1.reference', 'Drontal');
    }

    public function test_it_ignores_records_due_beyond_the_window(): void
    {
        $pet = Pet::factory()->create(['user_id' => $this->tutor->id]);
        $this->vaccinationDueIn($pet, 200);

        Sanctum::actingAs($this->tutor);

        $this->getJson('/api/pets/health-summary')
            ->assertOk()
            ->assertJsonCount(0, 'data.0.events');

        $this->getJson('/api/pets/health-summary?window_days=300')
            ->assertOk()
            ->assertJsonCount(1, 'data.0.events')
            ->assertJsonPath('meta.window_days', 300);
    }

    public function test_it_ignores_vaccinations_without_a_next_dose(): void
    {
        $pet = Pet::factory()->create(['user_id' => $this->tutor->id]);
        Vaccination::create([
            'pet_id' => $pet->id,
            'professional_id' => $this->veterinarian->id,
            'vaccine_name' => 'Antirrábica',
            'application_date' => now()->subDays(3)->toDateString(),
            'next_dose_date' => null,
        ]);

        Sanctum::actingAs($this->tutor);

        $this->getJson('/api/pets/health-summary')
            ->assertOk()
            ->assertJsonCount(0, 'data.0.events');
    }

    public function test_it_rejects_an_out_of_range_window(): void
    {
        Sanctum::actingAs($this->tutor);

        $this->getJson('/api/pets/health-summary?window_days=0')
            ->assertStatus(422)
            ->assertJsonValidationErrors('window_days');
    }

    /**
     * The whole point of the endpoint: the number of queries must not grow with
     * the number of pets, otherwise the N+1 just moved from the client to PHP.
     */
    public function test_query_count_does_not_grow_with_the_number_of_pets(): void
    {
        Sanctum::actingAs($this->tutor);

        $this->createPetsWithRecords(2);
        $withTwoPets = $this->countQueriesOfSummaryRequest();

        $this->createPetsWithRecords(8);
        $withTenPets = $this->countQueriesOfSummaryRequest();

        $this->assertSame(
            self::EXPECTED_QUERY_COUNT,
            $withTwoPets,
            "Expected {$withTwoPets} to be the constrained eager load: pets + vaccinations + dewormings."
        );

        $this->assertSame(
            $withTwoPets,
            $withTenPets,
            "Query count grew from {$withTwoPets} (2 pets) to {$withTenPets} (10 pets) — N+1 reintroduced."
        );
    }

    private function countQueriesOfSummaryRequest(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson('/api/pets/health-summary')->assertOk();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function createPetsWithRecords(int $quantity): void
    {
        for ($index = 0; $index < $quantity; $index++) {
            $pet = Pet::factory()->create(['user_id' => $this->tutor->id]);
            $this->vaccinationDueIn($pet, 15);
            $this->dewormingDueIn($pet, 20);
        }
    }

    private function vaccinationDueIn(Pet $pet, int $days, string $name = 'V8'): Vaccination
    {
        return Vaccination::create([
            'pet_id' => $pet->id,
            'professional_id' => $this->veterinarian->id,
            'vaccine_name' => $name,
            'application_date' => now()->subYear()->toDateString(),
            'next_dose_date' => now()->addDays($days)->toDateString(),
        ]);
    }

    private function dewormingDueIn(Pet $pet, int $days, string $name = 'Vermífugo'): PetDeworming
    {
        return PetDeworming::create([
            'pet_id' => $pet->id,
            'product_name' => $name,
            'applied_date' => now()->subMonths(3)->toDateString(),
            'next_date' => now()->addDays($days)->toDateString(),
        ]);
    }
}
