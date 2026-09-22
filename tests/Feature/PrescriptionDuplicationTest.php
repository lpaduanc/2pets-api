<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `POST prescriptions/{id}/duplicate` — alternativa barata a um modelo de prescrição
 * nomeado (spec docs/gap-simplesvet/specs/12-internacao-mapa-execucao-spec.md, "Fora de
 * escopo"). Clona os itens para uma nova prescrição rascunho, sem tocar na original.
 */
class PrescriptionDuplicationTest extends TestCase
{
    use RefreshDatabase;

    private User $vet;

    private Pet $pet;

    private Prescription $original;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vet = User::factory()->professional()->create();
        $tutor = User::factory()->tutor()->create();
        $this->pet = Pet::factory()->create(['user_id' => $tutor->id]);

        PetVetAccess::create([
            'pet_id' => $this->pet->id,
            'veterinarian_id' => $this->vet->id,
            'granted_by' => $tutor->id,
            'access_level' => VetAccessLevel::WRITE,
            'status' => PetVetAccess::STATUS_ACCEPTED,
            'is_active' => true,
            'granted_at' => now(),
            'responded_at' => now(),
        ]);

        Sanctum::actingAs($this->vet);

        $this->original = Prescription::create([
            'pet_id' => $this->pet->id,
            'professional_id' => $this->vet->id,
            'prescription_date' => now()->subDays(10)->toDateString(),
            'standalone_reason' => 'remote_orientation',
        ]);
        $this->original->items()->create([
            'position' => 1,
            'commercial_name' => 'Amoxicilina',
            'dose_value' => 250,
            'dose_unit' => 'mg',
            'frequency' => 'bid',
            'duration_text' => '7 dias',
        ]);
    }

    public function test_duplicate_creates_a_new_draft_with_the_same_items(): void
    {
        $response = $this->postJson("/api/professional/prescriptions/{$this->original->id}/duplicate");

        $response->assertStatus(201);
        $this->assertNotSame($this->original->id, $response->json('data.id'));
        $this->assertNull($response->json('data.issued_at'));
        $this->assertSame('Amoxicilina', $response->json('data.items.0.commercial_name'));
    }

    public function test_duplicate_does_not_modify_the_original_prescription(): void
    {
        $this->postJson("/api/professional/prescriptions/{$this->original->id}/duplicate")->assertStatus(201);

        $this->assertDatabaseHas('prescriptions', [
            'id' => $this->original->id,
            'prescription_date' => now()->subDays(10)->toDateString(),
        ]);
        $this->assertSame(1, $this->original->items()->count());
    }
}
