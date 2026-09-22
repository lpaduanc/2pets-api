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
 * `POST prescriptions/{id}/sign` — contrato docs/gap-simplesvet/specs/
 * 15-modelos-documento-receituario-assinatura-spec.md: assinatura eletrônica SIMPLES (nome +
 * CRMV + timestamp + hash), idempotente — chamar de novo não gera um segundo código.
 *
 * Escrito conforme a regra do projeto: teste escrito, NÃO executado via `artisan test`.
 */
class SimpleElectronicSignatureTest extends TestCase
{
    use RefreshDatabase;

    private User $vet;

    private Prescription $prescription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vet = User::factory()->professional()->create();
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

        $this->prescription = Prescription::create([
            'pet_id' => $pet->id,
            'professional_id' => $this->vet->id,
            'prescription_date' => now()->toDateString(),
            'standalone_reason' => 'remote_orientation',
        ]);
        $this->prescription->items()->create(['position' => 1, 'commercial_name' => 'Amoxicilina']);

        Sanctum::actingAs($this->vet);
    }

    public function test_signing_grants_hash_signed_at_and_verification_code(): void
    {
        $response = $this->postJson("/api/professional/prescriptions/{$this->prescription->id}/sign");

        $response->assertOk()
            ->assertJsonPath('data.signature_type', 'simple_electronic')
            ->assertJsonPath('data.id', $this->prescription->id);

        $this->assertNotNull($response->json('data.signed_at'));
        $this->assertNotNull($response->json('data.verification_code'));
    }

    public function test_signing_twice_keeps_the_same_verification_code(): void
    {
        $first = $this->postJson("/api/professional/prescriptions/{$this->prescription->id}/sign");
        $firstCode = $first->json('data.verification_code');

        $second = $this->postJson("/api/professional/prescriptions/{$this->prescription->id}/sign");
        $secondCode = $second->json('data.verification_code');

        $this->assertSame($firstCode, $secondCode);
    }

    public function test_another_professionals_prescription_cannot_be_signed(): void
    {
        $someoneElse = User::factory()->professional()->create();
        Sanctum::actingAs($someoneElse);

        $this->postJson("/api/professional/prescriptions/{$this->prescription->id}/sign")
            ->assertStatus(404);
    }
}
