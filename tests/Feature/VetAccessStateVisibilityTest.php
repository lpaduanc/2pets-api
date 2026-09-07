<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
use App\Enums\VetAccessState;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * O vet precisa enxergar, JÁ NO RESULTADO DA BUSCA, o estado do seu vínculo com cada pet do
 * tutor. Um tutor tem vários pets e cada um tem vínculo próprio — descobrir o conflito só no
 * 409, depois de escolher, obrigava a tentar um pet de cada vez.
 */
class VetAccessStateVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private const TUTOR_CPF = '39053344705';

    private User $tutor;

    private User $vet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tutor = User::factory()->tutor()->create(['cpf' => self::TUTOR_CPF]);
        $this->vet = User::factory()->veterinarian()->create();
    }

    public function test_enum_values_mirror_the_model_status_constants(): void
    {
        $this->assertSame(PetVetAccess::STATUS_PENDING, VetAccessState::PENDING->value);
        $this->assertSame(PetVetAccess::STATUS_ACCEPTED, VetAccessState::ACCEPTED->value);
        $this->assertSame(PetVetAccess::STATUS_REJECTED, VetAccessState::REJECTED->value);
        $this->assertSame(PetVetAccess::STATUS_REVOKED, VetAccessState::REVOKED->value);
        $this->assertSame(PetVetAccess::STATUS_SUPERSEDED, VetAccessState::SUPERSEDED->value);
    }

    public function test_pet_without_any_link_is_requestable(): void
    {
        Pet::factory()->create(['user_id' => $this->tutor->id, 'name' => 'Toddy']);

        $this->searchAsVet()
            ->assertJsonPath('data.0.vet_access.status', 'none')
            ->assertJsonPath('data.0.vet_access.can_request', true)
            ->assertJsonPath('data.0.vet_access.access_id', null);
    }

    public function test_pending_link_is_reported_and_blocks_a_new_request(): void
    {
        $pet = Pet::factory()->create(['user_id' => $this->tutor->id, 'name' => 'Luna']);
        PetVetAccess::factoryCreatePending($this->vet, $pet);

        $this->searchAsVet()
            ->assertJsonPath('data.0.vet_access.status', 'pending')
            ->assertJsonPath('data.0.vet_access.can_request', false);
    }

    /**
     * Desde que o tutor passou a decidir o nível, "aceito" não bloqueia mais por si só: um
     * acesso `full` cobre tudo e encerra o assunto, um `read` ainda comporta upgrade. Os dois
     * lados dessa regra estão em VetAccessLevelDecisionTest.
     */
    public function test_accepted_link_at_the_highest_level_blocks_a_new_request(): void
    {
        $pet = Pet::factory()->create(['user_id' => $this->tutor->id, 'name' => 'Bella']);
        PetVetAccess::factoryCreatePending($this->vet, $pet)->accept(VetAccessLevel::FULL);

        $this->searchAsVet()
            ->assertJsonPath('data.0.vet_access.status', 'accepted')
            ->assertJsonPath('data.0.vet_access.can_request', false)
            ->assertJsonPath('data.0.vet_access.since', today()->toDateString());
    }

    public function test_rejected_link_allows_a_new_request(): void
    {
        $pet = Pet::factory()->create(['user_id' => $this->tutor->id, 'name' => 'Max']);
        PetVetAccess::factoryCreatePending($this->vet, $pet)->reject('Não reconheço');

        $this->searchAsVet()
            ->assertJsonPath('data.0.vet_access.status', 'rejected')
            ->assertJsonPath('data.0.vet_access.can_request', true);
    }

    public function test_revoked_link_allows_a_new_request(): void
    {
        $pet = Pet::factory()->create(['user_id' => $this->tutor->id, 'name' => 'Nina']);
        $access = PetVetAccess::factoryCreatePending($this->vet, $pet);
        $access->accept(VetAccessLevel::READ);
        $access->revoke($this->tutor->id, 'Troquei de veterinário');

        $this->searchAsVet()
            ->assertJsonPath('data.0.vet_access.status', 'revoked')
            ->assertJsonPath('data.0.vet_access.can_request', true);
    }

    /** Cenário exato relatado: um tutor, vários pets, cada um num estado diferente. */
    public function test_tutor_with_many_pets_reports_one_state_per_pet(): void
    {
        $this->petNamed('Bella', fn (PetVetAccess $access) => $access->accept(VetAccessLevel::FULL));
        $this->petNamed('Luna', null);
        $this->petNamed('Max', fn (PetVetAccess $access) => $access->reject(null));
        $this->petNamed('Toddy', fn () => null);

        $response = $this->searchAsVet()->assertJsonCount(4, 'data');

        $byName = collect($response->json('data'))->keyBy('name');

        $this->assertSame('accepted', $byName['Bella']['vet_access']['status']);
        $this->assertFalse($byName['Bella']['vet_access']['can_request']);

        $this->assertSame('none', $byName['Luna']['vet_access']['status']);
        $this->assertTrue($byName['Luna']['vet_access']['can_request']);

        $this->assertSame('rejected', $byName['Max']['vet_access']['status']);
        $this->assertTrue($byName['Max']['vet_access']['can_request']);

        $this->assertSame('pending', $byName['Toddy']['vet_access']['status']);
        $this->assertFalse($byName['Toddy']['vet_access']['can_request']);
    }

    public function test_state_is_scoped_to_the_requesting_vet(): void
    {
        $pet = Pet::factory()->create(['user_id' => $this->tutor->id, 'name' => 'Bella']);
        $otherVet = User::factory()->veterinarian()->create();
        PetVetAccess::factoryCreatePending($otherVet, $pet)->accept(VetAccessLevel::FULL);

        $this->searchAsVet()
            ->assertJsonPath('data.0.vet_access.status', 'none')
            ->assertJsonPath('data.0.vet_access.can_request', true);
    }

    public function test_conflict_on_accepted_link_says_access_is_already_granted(): void
    {
        $pet = Pet::factory()->create(['user_id' => $this->tutor->id, 'name' => 'Bella']);
        PetVetAccess::factoryCreatePending($this->vet, $pet)->accept(VetAccessLevel::READ);

        Sanctum::actingAs($this->vet);

        $response = $this->postJson('/api/pet-vet-access/request', ['pet_id' => $pet->id])
            ->assertStatus(409)
            ->assertJsonPath('data.status', 'accepted');

        $this->assertStringContainsString('Você já tem acesso a Bella', $response->json('message'));
    }

    public function test_conflict_on_pending_link_says_it_awaits_the_tutor(): void
    {
        $pet = Pet::factory()->create(['user_id' => $this->tutor->id, 'name' => 'Luna']);
        PetVetAccess::factoryCreatePending($this->vet, $pet);

        Sanctum::actingAs($this->vet);

        $response = $this->postJson('/api/pet-vet-access/request', ['pet_id' => $pet->id])
            ->assertStatus(409)
            ->assertJsonPath('data.status', 'pending');

        $this->assertStringContainsString('aguardando resposta do tutor', $response->json('message'));
    }

    public function test_search_does_not_run_one_query_per_pet(): void
    {
        foreach (['Bella', 'Luna', 'Max', 'Toddy', 'Nina'] as $name) {
            $this->petNamed($name, fn (PetVetAccess $access) => $access->accept(VetAccessLevel::FULL));
        }

        Sanctum::actingAs($this->vet);

        \DB::enableQueryLog();
        $this->getJson('/api/pets/search?tutor_cpf='.self::TUTOR_CPF)->assertOk();
        $queryCount = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        // count + página + tutor + raça + acessos = 5. Cresce com o número de RELAÇÕES,
        // nunca com o número de pets.
        $this->assertLessThanOrEqual(6, $queryCount, "Busca disparou {$queryCount} queries — suspeita de N+1.");
    }

    private function petNamed(string $name, ?callable $mutateAccess): Pet
    {
        $pet = Pet::factory()->create(['user_id' => $this->tutor->id, 'name' => $name]);

        if ($mutateAccess === null) {
            return $pet;
        }

        $mutateAccess(PetVetAccess::factoryCreatePending($this->vet, $pet));

        return $pet;
    }

    private function searchAsVet(): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($this->vet);

        return $this->getJson('/api/pets/search?tutor_cpf='.self::TUTOR_CPF)->assertOk();
    }
}
