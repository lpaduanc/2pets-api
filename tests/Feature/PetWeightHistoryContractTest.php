<?php

namespace Tests\Feature;

use App\Models\Pet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression test for the weight-history field name mismatch: `POST
 * /api/pets/{pet}/weights` used to only accept `weight_kg`/`note` while the response
 * (and `PetWeightHistory`'s own columns) used `weight`/`notes` — the real frontend
 * widget (`PetWeightSection.vue`) already posts `weight`, which used to 422 with
 * "weight_kg é obrigatório". `weight`/`notes` are now canonical; `weight_kg`/`note`
 * remain accepted as a compatibility alias.
 */
class PetWeightHistoryContractTest extends TestCase
{
    use RefreshDatabase;

    private User $tutor;

    private Pet $pet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tutor = User::factory()->tutor()->create();
        $this->pet = Pet::factory()->create(['user_id' => $this->tutor->id]);

        Sanctum::actingAs($this->tutor);
    }

    public function test_it_accepts_the_canonical_weight_and_notes_fields(): void
    {
        $response = $this->postJson("/api/pets/{$this->pet->id}/weights", [
            'weight' => 12.5,
            'measured_at' => now()->toDateString(),
            'notes' => 'Pesagem de rotina',
        ]);

        $response->assertCreated();

        $this->assertSame(12.5, (float) $response->json('data.weight'));
        $this->assertSame('Pesagem de rotina', $response->json('data.notes'));

        $this->assertDatabaseHas('pet_weight_history', [
            'pet_id' => $this->pet->id,
            'notes' => 'Pesagem de rotina',
        ]);
    }

    /**
     * Contrato antigo, aceito como alias — nunca deve 422 nem descartar a nota em silêncio.
     */
    public function test_it_still_accepts_the_legacy_weight_kg_and_note_fields(): void
    {
        $response = $this->postJson("/api/pets/{$this->pet->id}/weights", [
            'weight_kg' => 9.8,
            'measured_at' => now()->toDateString(),
            'note' => 'Contrato legado',
        ]);

        $response->assertCreated();

        $this->assertSame(9.8, (float) $response->json('data.weight'));
        $this->assertSame('Contrato legado', $response->json('data.notes'));
    }

    public function test_it_rejects_missing_weight(): void
    {
        $response = $this->postJson("/api/pets/{$this->pet->id}/weights", [
            'measured_at' => now()->toDateString(),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('weight');
    }
}
