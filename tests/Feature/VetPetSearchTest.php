<?php

namespace Tests\Feature;

use App\Models\Pet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `GET /api/pets/search` — o passo que antecede a solicitação de acesso.
 *
 * O foco é o contrato de entrada (CPF mascarado x limpo), o recorte de autorização e o que
 * NÃO pode vazar: o vet ainda não tem consentimento nenhum sobre esses dados.
 */
class VetPetSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $tutor;

    private User $vet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tutor = User::factory()->tutor()->create([
            'cpf' => '39053344705',
            'name' => 'Marcela Nogueira',
        ]);
        $this->vet = User::factory()->veterinarian()->create();
    }

    public function test_vet_finds_pets_by_clean_tutor_cpf(): void
    {
        Pet::factory()->create(['user_id' => $this->tutor->id, 'name' => 'Bella']);

        Sanctum::actingAs($this->vet);

        $this->getJson('/api/pets/search?tutor_cpf=39053344705')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Bella')
            ->assertJsonPath('data.0.tutor.name', 'Marcela Nogueira');
    }

    public function test_vet_finds_pets_by_masked_tutor_cpf(): void
    {
        Pet::factory()->create(['user_id' => $this->tutor->id, 'name' => 'Bella']);

        Sanctum::actingAs($this->vet);

        // Regressão: o app envia o CPF com máscara e a busca precisa normalizar antes
        // de consultar a coluna, que guarda só dígitos.
        $this->getJson('/api/pets/search?tutor_cpf=390.533.447-05')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Bella');
    }

    public function test_search_by_microchip_returns_the_pet(): void
    {
        Pet::factory()->create([
            'user_id' => $this->tutor->id,
            'name' => 'Thor',
            'microchip_number' => '900123456789012',
        ]);
        Pet::factory()->create(['user_id' => $this->tutor->id, 'name' => 'Nina']);

        Sanctum::actingAs($this->vet);

        $this->getJson('/api/pets/search?microchip_number=900123456789012')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Thor');
    }

    public function test_unknown_cpf_returns_empty_list_not_an_error(): void
    {
        Sanctum::actingAs($this->vet);

        $this->getJson('/api/pets/search?tutor_cpf=11122233344')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_cpf_with_wrong_length_is_rejected(): void
    {
        Sanctum::actingAs($this->vet);

        $this->getJson('/api/pets/search?tutor_cpf=3905334')
            ->assertStatus(422)
            ->assertJsonValidationErrors('tutor_cpf');
    }

    public function test_search_without_any_identifier_is_rejected(): void
    {
        Sanctum::actingAs($this->vet);

        $this->getJson('/api/pets/search')->assertStatus(422);
    }

    public function test_tutor_cannot_search_other_peoples_pets(): void
    {
        Pet::factory()->create(['user_id' => $this->tutor->id]);
        $otherTutor = User::factory()->tutor()->create();

        Sanctum::actingAs($otherTutor);

        $this->getJson('/api/pets/search?tutor_cpf=39053344705')->assertForbidden();
    }

    public function test_guest_cannot_search(): void
    {
        $this->getJson('/api/pets/search?tutor_cpf=39053344705')->assertUnauthorized();
    }

    public function test_search_result_does_not_leak_tutor_contact_data(): void
    {
        Pet::factory()->create(['user_id' => $this->tutor->id]);

        Sanctum::actingAs($this->vet);

        $response = $this->getJson('/api/pets/search?tutor_cpf=39053344705')->assertOk();

        $tutorPayload = $response->json('data.0.tutor');
        $this->assertSame(['id', 'name'], array_keys($tutorPayload));
        $response->assertJsonMissing(['cpf' => $this->tutor->cpf]);
        $response->assertJsonMissing(['email' => $this->tutor->email]);
    }
}
