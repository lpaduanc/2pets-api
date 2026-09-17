<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
use App\Models\Appointment;
use App\Models\MedicalRecord;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Invariante de privacidade — contrato
 * docs/atendimento-veterinario/08-consulta-autorizada-por-agendamento.md §B: um profissional
 * que só chegou ao pet por AGENDAMENTO (sem `PetVetAccess`) só pode ver a identidade mínima do
 * pet (nome, espécie, raça, sexo, porte, foto) — nunca o cadastro clínico completo (peso,
 * castração, microchip etc.).
 *
 * Escrito conforme a regra do projeto: teste escrito, NÃO executado via `artisan test`.
 */
class ConsultationPetPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private User $professional;

    private User $tutor;

    private Pet $pet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->professional = User::factory()->professional()->create();
        $this->tutor = User::factory()->tutor()->create();
        $this->pet = Pet::factory()->create([
            'user_id' => $this->tutor->id,
            'weight' => 12.3,
            'neutered_status' => 'yes',
            'microchip_number' => 'ABC123456',
        ]);

        Sanctum::actingAs($this->professional);
    }

    public function test_start_response_hides_full_pet_cadastro_without_pet_vet_access(): void
    {
        $appointment = $this->createAppointment('confirmed');

        $response = $this->postJson("/api/professional/appointments/{$appointment->id}/start");

        $response->assertOk()
            ->assertJsonPath('data.medical_record.pet.name', $this->pet->name)
            ->assertJsonPath('data.medical_record.pet.weight', null)
            ->assertJsonPath('data.medical_record.pet.microchip_number', null)
            ->assertJsonPath('data.appointment.pet.weight', null);
    }

    public function test_start_response_shows_full_pet_cadastro_with_pet_vet_access(): void
    {
        $this->grantVetAccess(VetAccessLevel::WRITE);
        $appointment = $this->createAppointment('confirmed');

        $response = $this->postJson("/api/professional/appointments/{$appointment->id}/start");

        $response->assertOk()
            ->assertJsonPath('data.medical_record.pet.weight', 12.3)
            ->assertJsonPath('data.medical_record.pet.microchip_number', 'ABC123456');
    }

    public function test_pending_list_hides_full_pet_cadastro_without_pet_vet_access(): void
    {
        $appointment = $this->createAppointment('confirmed');
        $this->postJson("/api/professional/appointments/{$appointment->id}/start")->assertOk();

        $response = $this->getJson('/api/professional/medical-records/pending');

        $response->assertOk()
            ->assertJsonPath('data.0.pet.weight', null)
            ->assertJsonPath('data.0.pet.name', $this->pet->name);
    }

    public function test_finalize_response_hides_full_pet_cadastro_without_pet_vet_access(): void
    {
        $record = $this->createDraftRecord();
        $record->update(['weight' => 10, 'diagnosis' => 'Otite']);

        $response = $this->postJson("/api/professional/medical-records/{$record->id}/finalize");

        $response->assertOk()
            ->assertJsonPath('data.pet.weight', null)
            ->assertJsonPath('data.pet.microchip_number', null);
    }

    public function test_shared_show_endpoint_hides_full_pet_cadastro_for_author_without_grant(): void
    {
        $record = $this->createDraftRecord();

        $response = $this->getJson("/api/medical-records/{$record->id}");

        $response->assertOk()->assertJsonPath('data.pet.weight', null);
    }

    /**
     * Mesmo shape legado ({message, record}) — `MedicalRecordController::index` continua
     * devolvendo o model cru, mas o `pet` embutido precisa vir minimizado igual.
     */
    public function test_legacy_professional_records_list_hides_full_pet_cadastro_without_grant(): void
    {
        $this->createDraftRecord();

        $response = $this->getJson('/api/professional/medical-records');

        $response->assertOk();
        $this->assertNull($response->json('data.0.pet.weight'));
        $this->assertSame($this->pet->name, $response->json('data.0.pet.name'));
    }

    private function grantVetAccess(VetAccessLevel $level): void
    {
        PetVetAccess::create([
            'pet_id' => $this->pet->id,
            'veterinarian_id' => $this->professional->id,
            'granted_by' => $this->tutor->id,
            'access_level' => $level,
            'status' => PetVetAccess::STATUS_ACCEPTED,
            'is_active' => true,
            'granted_at' => now(),
            'responded_at' => now(),
        ]);
    }

    private function createAppointment(string $status): Appointment
    {
        return Appointment::create([
            'professional_id' => $this->professional->id,
            'client_id' => $this->tutor->id,
            'pet_id' => $this->pet->id,
            'appointment_date' => now()->addDay()->startOfDay(),
            'appointment_time' => '10:00:00',
            'duration' => 30,
            'type' => 'consultation',
            'status' => $status,
        ]);
    }

    private function createDraftRecord(): MedicalRecord
    {
        $appointment = $this->createAppointment('confirmed');
        $response = $this->postJson("/api/professional/appointments/{$appointment->id}/start");

        return MedicalRecord::findOrFail($response->json('data.medical_record.id'));
    }
}
