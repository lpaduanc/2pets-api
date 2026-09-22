<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
use App\Models\Hospitalization;
use App\Models\HospitalizationBox;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regra de negócio 1 da spec docs/gap-simplesvet/specs/12-internacao-mapa-execucao-spec.md:
 * um box só aceita UMA internação `active` por vez. Cobre admissão sem `risk_level`
 * (regra de negócio 2 — nunca obrigatório).
 */
class HospitalizationBoxOccupancyTest extends TestCase
{
    use RefreshDatabase;

    private User $vet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vet = User::factory()->professional()->create();
        Sanctum::actingAs($this->vet);
    }

    private function petWithAccess(): Pet
    {
        $tutor = User::factory()->tutor()->create();
        $pet = Pet::factory()->create(['user_id' => $tutor->id]);

        PetVetAccess::create([
            'pet_id' => $pet->id,
            'veterinarian_id' => $this->vet->id,
            'granted_by' => $tutor->id,
            'access_level' => VetAccessLevel::WRITE,
            'status' => PetVetAccess::STATUS_ACCEPTED,
            'is_active' => true,
            'granted_at' => now(),
            'responded_at' => now(),
        ]);

        return $pet;
    }

    public function test_admission_without_risk_level_or_box_is_accepted(): void
    {
        $response = $this->postJson('/api/professional/hospitalizations', [
            'pet_id' => $this->petWithAccess()->id,
            'admission_date' => now()->toDateString(),
            'reason' => 'Pós-operatório eletivo',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.box_id', null)
            ->assertJsonPath('data.risk_level', null);
    }

    public function test_admission_accepts_a_valid_risk_level(): void
    {
        $response = $this->postJson('/api/professional/hospitalizations', [
            'pet_id' => $this->petWithAccess()->id,
            'admission_date' => now()->toDateString(),
            'reason' => 'Emergência',
            'risk_level' => 'emergency',
        ]);

        $response->assertStatus(201)->assertJsonPath('data.risk_level', 'emergency');
    }

    public function test_two_active_hospitalizations_cannot_share_the_same_box(): void
    {
        $box = HospitalizationBox::create(['professional_id' => $this->vet->id, 'name' => 'Box 1']);

        $first = $this->postJson('/api/professional/hospitalizations', [
            'pet_id' => $this->petWithAccess()->id,
            'admission_date' => now()->toDateString(),
            'reason' => 'Internação A',
            'box_id' => $box->id,
        ]);
        $first->assertStatus(201);

        $second = $this->postJson('/api/professional/hospitalizations', [
            'pet_id' => $this->petWithAccess()->id,
            'admission_date' => now()->toDateString(),
            'reason' => 'Internação B',
            'box_id' => $box->id,
        ]);

        $second->assertStatus(422);
    }

    public function test_box_freed_after_discharge_can_be_reused(): void
    {
        $box = HospitalizationBox::create(['professional_id' => $this->vet->id, 'name' => 'Box 1']);

        $first = Hospitalization::findOrFail($this->postJson('/api/professional/hospitalizations', [
            'pet_id' => $this->petWithAccess()->id,
            'admission_date' => now()->toDateString(),
            'reason' => 'Internação A',
            'box_id' => $box->id,
        ])->assertStatus(201)->json('data.id'));

        $this->putJson("/api/professional/hospitalizations/{$first->id}", [
            'status' => 'discharged',
            'discharge_date' => now()->toDateString(),
            'discharge_summary' => 'Alta sem intercorrências.',
        ])->assertStatus(200);

        $second = $this->postJson('/api/professional/hospitalizations', [
            'pet_id' => $this->petWithAccess()->id,
            'admission_date' => now()->toDateString(),
            'reason' => 'Internação B',
            'box_id' => $box->id,
        ]);

        $second->assertStatus(201);
    }
}
