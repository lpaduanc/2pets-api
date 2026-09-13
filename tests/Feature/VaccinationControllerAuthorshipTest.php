<?php

namespace Tests\Feature;

use App\Models\Pet;
use App\Models\User;
use App\Models\Vaccination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/vinculo-estoque-aplicacao-clinica.md item 0: `VaccinationController` (legada, mas ainda
 * exposta em `/api/professional/vaccinations`) gravava sempre `professional_id =
 * $request->user()->id`, mesmo quando quem chamava era o próprio tutor do pet — diferente de
 * `PetHealthRecordsController::resolveProfessionalId()`, que grava `null` nesse caso para não
 * fabricar autoria clínica de alguém que não participou do ato.
 */
class VaccinationControllerAuthorshipTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_tutor_registering_their_own_pets_vaccination_never_claims_professional_authorship(): void
    {
        $tutor = User::factory()->tutor()->create();
        $pet = Pet::factory()->create(['user_id' => $tutor->id]);

        Sanctum::actingAs($tutor);

        $response = $this->postJson('/api/professional/vaccinations', [
            'pet_id' => $pet->id,
            'vaccine_name' => 'V10',
            'application_date' => now()->toDateString(),
        ]);

        $response->assertCreated();

        $vaccination = Vaccination::where('pet_id', $pet->id)->firstOrFail();
        $this->assertNull($vaccination->professional_id);
    }

    public function test_a_vet_registering_a_vaccination_is_recorded_as_the_author(): void
    {
        $tutor = User::factory()->tutor()->create();
        $vet = User::factory()->professional()->create();
        $pet = Pet::factory()->create(['user_id' => $tutor->id]);

        \App\Models\PetVetAccess::create([
            'pet_id' => $pet->id,
            'veterinarian_id' => $vet->id,
            'granted_by' => $tutor->id,
            'access_level' => \App\Enums\VetAccessLevel::WRITE,
            'status' => \App\Models\PetVetAccess::STATUS_ACCEPTED,
            'is_active' => true,
            'granted_at' => now(),
            'responded_at' => now(),
        ]);

        Sanctum::actingAs($vet);

        $response = $this->postJson('/api/professional/vaccinations', [
            'pet_id' => $pet->id,
            'vaccine_name' => 'V10',
            'application_date' => now()->toDateString(),
        ]);

        $response->assertCreated();

        $vaccination = Vaccination::where('pet_id', $pet->id)->firstOrFail();
        $this->assertSame($vet->id, $vaccination->professional_id);
    }
}
