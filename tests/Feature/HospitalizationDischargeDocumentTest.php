<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
use App\Models\Hospitalization;
use App\Models\MedicalRecord;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Documento de alta — contrato docs/atendimento-veterinario/12-modulo-clinico-internacao.md
 * §5.2: montagem de tela, não subsistema novo. Cobre os dois campos agregados que o backend
 * PRECISA expor sem inventar nada — `indicating_medical_record` (resumo) e
 * `follow_up_appointment` (reaproveita `MedicalRecord.follow_up_appointment_id`, doc 00 §6).
 *
 * Escrito conforme a regra do projeto: teste escrito, NÃO executado via `artisan test`
 * (verificado ao vivo via `curl`/`tinker` contra o ambiente de dev — ver relato da tarefa).
 */
class HospitalizationDischargeDocumentTest extends TestCase
{
    use RefreshDatabase;

    private User $vet;

    private User $tutor;

    private Pet $pet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vet = User::factory()->professional()->create();
        $this->tutor = User::factory()->tutor()->create();
        $this->pet = Pet::factory()->create(['user_id' => $this->tutor->id]);

        PetVetAccess::create([
            'pet_id' => $this->pet->id,
            'veterinarian_id' => $this->vet->id,
            'granted_by' => $this->tutor->id,
            'access_level' => VetAccessLevel::WRITE,
            'status' => PetVetAccess::STATUS_ACCEPTED,
            'is_active' => true,
            'granted_at' => now(),
            'responded_at' => now(),
        ]);

        Sanctum::actingAs($this->vet);
    }

    /** §3.1: prontuário de OUTRO atendimento que indicou internar, resumido no topo da ficha. */
    public function test_indicating_medical_record_summary_is_exposed_without_the_full_record(): void
    {
        $indicatingRecord = MedicalRecord::create([
            'pet_id' => $this->pet->id,
            'professional_id' => $this->vet->id,
            'record_date' => now()->toDateString(),
            'status' => 'finalized',
            'finalized_at' => now(),
            'finalized_by' => $this->vet->id,
            'weight' => 4.0,
            'diagnosis' => 'Gastroenterite hemorrágica grave',
        ]);

        $response = $this->postJson('/api/professional/hospitalizations', [
            'pet_id' => $this->pet->id,
            'admission_date' => now()->toDateString(),
            'reason' => 'Internação para tratamento',
            'indicating_medical_record_id' => $indicatingRecord->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.indicating_medical_record_id', $indicatingRecord->id)
            ->assertJsonPath('data.indicating_medical_record.diagnosis', 'Gastroenterite hemorrágica grave');
    }

    /**
     * §5.2: retorno agendado reaproveita `follow_up_appointment_id` do ato Grupo A finalizado
     * durante a internação — sem campo novo em `hospitalizations`. Formato é o
     * `AppointmentResource` padrão (`appointment_date`/`appointment_time`), não `starts_at`.
     */
    public function test_follow_up_scheduled_when_finalizing_a_clinical_act_is_exposed_on_the_hospitalization(): void
    {
        $hospitalization = $this->admit();

        $actId = $this->postJson("/api/professional/hospitalizations/{$hospitalization->id}/clinical-acts", [
            'category' => 'surgery',
            'reason' => 'Ovariohisterectomia',
            'unit_price' => 650,
        ])->json('data.id');

        $followUpDate = now()->addDays(10)->toDateString();
        $this->putJson("/api/professional/medical-records/{$actId}", [
            'weight' => 4.3,
            'diagnosis' => 'Cio persistente, indicação de OVH eletiva',
        ])->assertOk();
        $this->postJson("/api/professional/medical-records/{$actId}/finalize", [
            'follow_up' => ['date' => $followUpDate, 'reason' => 'Retirada de pontos'],
        ])->assertOk();

        $response = $this->getJson("/api/professional/hospitalizations/{$hospitalization->id}");

        // Decisão do dono do produto: retorno agendado é SEMPRE `consultation`, nunca herda o
        // `type` do agendamento do ato de origem — mesmo quando o ato está pendurado numa
        // internação. Ver `MedicalRecordFinalizationService::attachFollowUp`.
        $response->assertOk()
            ->assertJsonPath('data.follow_up_appointment.reason', 'Retirada de pontos')
            ->assertJsonPath('data.follow_up_appointment.type', 'consultation');
    }

    public function test_follow_up_appointment_is_null_when_no_act_scheduled_one(): void
    {
        $hospitalization = $this->admit();

        $this->getJson("/api/professional/hospitalizations/{$hospitalization->id}")
            ->assertOk()
            ->assertJsonPath('data.follow_up_appointment', null);
    }

    private function admit(): Hospitalization
    {
        $response = $this->postJson('/api/professional/hospitalizations', [
            'pet_id' => $this->pet->id,
            'admission_date' => now()->toDateString(),
            'reason' => 'Internação de teste',
        ]);

        $response->assertStatus(201);

        return Hospitalization::findOrFail($response->json('data.id'));
    }
}
