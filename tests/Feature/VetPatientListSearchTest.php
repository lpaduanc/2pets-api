<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Busca textual da carteira de pacientes (`?q=`), que substituiu o filtro em JavaScript sobre
 * a página já carregada — aquele não enxergava nada fora das primeiras 20 linhas.
 *
 * Roda contra PostgreSQL real (pg_trgm + `immutable_unaccent`), então cobre também o
 * comportamento fuzzy/acento-insensível que só existe no banco de verdade.
 */
class VetPatientListSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $vet;

    private Pet $bella;

    private Pet $thor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vet = User::factory()->veterinarian()->create();

        $this->bella = $this->grantAccessToNewPet('Bella', 'Marcela Nogueira');
        $this->thor = $this->grantAccessToNewPet('Thor', 'Ricardo Assunção');
    }

    public function test_list_without_term_returns_every_active_patient(): void
    {
        Sanctum::actingAs($this->vet);

        $this->getJson('/api/pet-vet-access/my-accesses')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_term_filters_by_pet_name(): void
    {
        Sanctum::actingAs($this->vet);

        $this->getJson('/api/pet-vet-access/my-accesses?q=bella')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.pet.name', 'Bella');
    }

    public function test_term_filters_by_tutor_name(): void
    {
        Sanctum::actingAs($this->vet);

        $this->getJson('/api/pet-vet-access/my-accesses?q=Ricardo')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.pet.name', 'Thor');
    }

    public function test_term_ignores_accents_and_typos(): void
    {
        Sanctum::actingAs($this->vet);

        // "Assuncao" sem cedilha nem til deve casar "Assunção" (immutable_unaccent + pg_trgm).
        $this->getJson('/api/pet-vet-access/my-accesses?q=Assuncao')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.pet.name', 'Thor');
    }

    public function test_term_that_matches_nothing_returns_empty_list(): void
    {
        Sanctum::actingAs($this->vet);

        $this->getJson('/api/pet-vet-access/my-accesses?q=xyzwk')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_term_shorter_than_the_trigram_floor_is_ignored(): void
    {
        Sanctum::actingAs($this->vet);

        // Abaixo de 3 caracteres a busca não é indexável — tratamos como "sem filtro"
        // em vez de forçar uma varredura sequencial.
        $this->getJson('/api/pet-vet-access/my-accesses?q=be')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_search_never_reaches_another_vets_patients(): void
    {
        $otherVet = User::factory()->veterinarian()->create();
        Sanctum::actingAs($otherVet);

        $this->getJson('/api/pet-vet-access/my-accesses?q=bella')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_revoked_access_disappears_from_the_search(): void
    {
        PetVetAccess::query()
            ->where('pet_id', $this->bella->id)
            ->where('veterinarian_id', $this->vet->id)
            ->firstOrFail()
            ->revoke($this->bella->user_id, 'Trocando de veterinário');

        Sanctum::actingAs($this->vet);

        $this->getJson('/api/pet-vet-access/my-accesses?q=bella')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_professional_alias_supports_the_same_term(): void
    {
        Sanctum::actingAs($this->vet);

        $this->getJson('/api/professional/my-patients?q=bella')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.pet.name', 'Bella');
    }

    private function grantAccessToNewPet(string $petName, string $tutorName): Pet
    {
        $tutor = User::factory()->tutor()->create(['name' => $tutorName]);
        $pet = Pet::factory()->create(['user_id' => $tutor->id, 'name' => $petName]);

        PetVetAccess::factoryCreatePending($this->vet, $pet)->accept(VetAccessLevel::READ);

        return $pet;
    }
}
