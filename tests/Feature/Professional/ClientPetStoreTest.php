<?php

namespace Tests\Feature\Professional;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Pet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * `POST professional/clients/{id}/pets` — cadastro de pet para cliente existente pelo pro
 * (recepção, pet shop e vet), e a mensagem/flag do "paciente novo" só de veterinário.
 */
class ClientPetStoreTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildClinic();
    }

    public function test_receptionist_registers_a_pet_for_a_team_client_and_it_shows_in_the_client_pets(): void
    {
        $response = $this->actingAs($this->receptionist, 'sanctum')->postJson("/api/professional/clients/{$this->tutor->id}/pets", [
            'name' => 'Paçoca', 'species' => 'dog', 'breed' => 'SRD', 'sex' => 'female', 'birth_date' => '2024-03-10', 'weight' => 8.4,
        ])->assertCreated()->assertJsonPath('data.name', 'Paçoca');

        $pet = Pet::findOrFail($response->json('data.id'));
        $this->assertSame([$this->tutor->id, 'dog', 'female', 'SRD'], [$pet->user_id, $pet->species, $pet->gender, $pet->breed]);

        foreach ([$this->receptionist, $this->owner] as $user) {
            $names = collect($this->actingAs($user, 'sanctum')->getJson("/api/professional/clients/{$this->tutor->id}/pets")->assertOk()->json())->pluck('name');
            $this->assertContains('Paçoca', $names->all());
        }
    }

    public function test_only_name_and_species_are_required_and_values_are_validated(): void
    {
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/professional/clients/{$this->tutor->id}/pets", ['name' => 'Mel', 'species' => 'cat'])
            ->assertCreated();
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/professional/clients/{$this->tutor->id}/pets", ['species' => 'dragon', 'sex' => 'x', 'birth_date' => '2999-01-01', 'weight' => -1])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'species', 'gender', 'birth_date', 'weight']);
    }

    public function test_a_client_outside_the_team_is_not_found(): void
    {
        $other = Organization::factory()->petshop()->create();
        $stranger = User::factory()->professional()->create();
        OrganizationMember::factory()->for($other, 'organization')->create(['user_id' => $stranger->id, 'role' => OrganizationRole::RECEPTIONIST->value]);
        $foreignTutor = User::factory()->tutor()->create();

        $this->actingAs($stranger, 'sanctum')->postJson("/api/professional/clients/{$this->tutor->id}/pets", ['name' => 'Intruso', 'species' => 'dog'])->assertNotFound();
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/professional/clients/{$foreignTutor->id}/pets", ['name' => 'Intruso', 'species' => 'dog'])->assertNotFound();
        $this->assertSame(0, Pet::where('name', 'Intruso')->count());
    }

    public function test_new_patient_is_refused_in_portuguese_for_a_clinic_receptionist_and_the_user_payload_says_who_is_a_vet(): void
    {
        // O `receptionist` da fixture também é vet volante (papel global) e a fixture é um pet shop
        // (que pode): aqui uma recepcionista pura de CLÍNICA, que continua sem poder.
        $desk = User::factory()->create();
        OrganizationMember::factory()->for(Organization::factory()->create(), 'organization')->create(['user_id' => $desk->id, 'role' => OrganizationRole::RECEPTIONIST->value]);

        $this->actingAs($desk->fresh(), 'sanctum')->postJson('/api/professional/appointments/new-patient', [])
            ->assertForbidden()->assertJsonPath('message', 'Seu perfil não pode cadastrar paciente novo pelo agendamento.');

        $this->actingAs($desk->fresh(), 'sanctum')->getJson('/api/user')->assertOk()->assertJsonPath('data.is_veterinarian', false)->assertJsonPath('data.can_register_new_patient', false);
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/user')->assertOk()->assertJsonPath('data.is_veterinarian', true);
    }
}
