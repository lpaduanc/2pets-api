<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
use App\Models\Appointment;
use App\Models\Exam;
use App\Models\Invoice;
use App\Models\MedicalRecord;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\Prescription;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Fluxo de atendimento — contrato docs/atendimento-veterinario/01-contrato-api-e-taxonomia.md §1
 * e docs/atendimento-veterinario/00-dominio-e-escopo.md §1.2/§1.3/§1.4.
 *
 * Escrito conforme a regra do projeto: teste escrito, NÃO executado via `artisan test`. A
 * cobertura foi validada manualmente via `curl` contra a API em execução (ver relatório).
 */
class ConsultationFlowTest extends TestCase
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
        $this->pet = Pet::factory()->create(['user_id' => $this->tutor->id]);

        $this->grantVetAccess(VetAccessLevel::WRITE);

        Sanctum::actingAs($this->professional);
    }

    public function test_start_transitions_appointment_and_creates_draft_record(): void
    {
        $appointment = $this->createAppointment('confirmed');

        $response = $this->postJson("/api/professional/appointments/{$appointment->id}/start");

        $response->assertOk()
            ->assertJsonPath('data.appointment.status', 'in_progress')
            ->assertJsonPath('data.medical_record.status', 'draft');

        $this->assertSame('in_progress', $appointment->fresh()->status);
        $this->assertDatabaseHas('medical_records', [
            'appointment_id' => $appointment->id,
            'pet_id' => $this->pet->id,
            'professional_id' => $this->professional->id,
            'status' => 'draft',
        ]);
    }

    public function test_start_is_idempotent_and_returns_the_same_draft(): void
    {
        $appointment = $this->createAppointment('confirmed');

        $first = $this->postJson("/api/professional/appointments/{$appointment->id}/start");
        $second = $this->postJson("/api/professional/appointments/{$appointment->id}/start");

        $first->assertOk();
        $second->assertOk();
        $this->assertSame(
            $first->json('data.medical_record.id'),
            $second->json('data.medical_record.id')
        );
        $this->assertSame(1, MedicalRecord::where('appointment_id', $appointment->id)->count());
    }

    /**
     * Contrato docs/atendimento-veterinario/09-faturamento-do-atendimento.md §13.5: sem
     * serviço/cobrança nenhuma lançada ainda (invariante 11), nenhuma fatura nasce — o campo
     * existe nos dois resources, mas vem `null`, nunca ausente.
     */
    public function test_start_response_has_null_invoice_id_when_nothing_was_billed_yet(): void
    {
        $appointment = $this->createAppointment('confirmed');

        $response = $this->postJson("/api/professional/appointments/{$appointment->id}/start");

        $response->assertOk()
            ->assertJsonPath('data.appointment.invoice_id', null)
            ->assertJsonPath('data.medical_record.invoice_id', null);

        $this->assertSame(0, Invoice::where('appointment_id', $appointment->id)->count());
    }

    /**
     * Contrato §13.5: o front, logo após iniciar/finalizar, precisa achar a fatura `pending`
     * do atendimento com um único `GET /invoices/{id}`, sem inventar filtro novo — por isso
     * `invoice_id` precisa vir preenchido nos DOIS resources assim que a fatura existe.
     */
    public function test_start_response_exposes_the_invoice_id_once_a_charge_is_billed(): void
    {
        $appointment = $this->createAppointment('confirmed');
        $this->postJson("/api/professional/appointments/{$appointment->id}/start")->assertOk();

        $this->postJson("/api/professional/appointments/{$appointment->id}/charges", [
            'description' => 'Consulta',
            'unit_price' => 150,
        ])->assertStatus(201);

        $invoice = Invoice::where('appointment_id', $appointment->id)->firstOrFail();

        $this->getJson("/api/professional/appointments/{$appointment->id}")
            ->assertOk()
            ->assertJsonPath('data.invoice_id', $invoice->id);
    }

    /**
     * Contrato §13.4/§13.5: banho e tosa não gera `MedicalRecord`, mas gera fatura igual a
     * qualquer outro tipo — "nenhum serviço fica de fora". `invoice_id` precisa vir
     * preenchido no `AppointmentResource` mesmo sem prontuário nenhum no payload.
     */
    public function test_grooming_start_exposes_the_invoice_id_without_a_medical_record(): void
    {
        $appointment = $this->createAppointment('confirmed', 'grooming');

        $started = $this->postJson("/api/professional/appointments/{$appointment->id}/start");
        $started->assertOk()
            ->assertJsonPath('data.medical_record', null)
            ->assertJsonPath('data.appointment.invoice_id', null);

        $this->postJson("/api/professional/appointments/{$appointment->id}/charges", [
            'description' => 'Banho e tosa',
            'unit_price' => 80,
        ])->assertStatus(201);

        $invoice = Invoice::where('appointment_id', $appointment->id)->firstOrFail();

        $this->getJson("/api/professional/appointments/{$appointment->id}")
            ->assertOk()
            ->assertJsonPath('data.invoice_id', $invoice->id);
    }

    /**
     * Contrato docs/atendimento-veterinario/10-taxonomia-servico-tipo-atendimento.md §2
     * Grupo C: `laboratory`/`imaging` não é ato clínico do tipo `MedicalRecord` — o laudo é
     * outro relógio. Exigir diagnóstico de quem só executou um ECG era exatamente o bug
     * relatado que originou esta correção.
     */
    public function test_start_on_imaging_appointment_creates_an_exam_instead_of_a_medical_record(): void
    {
        $appointment = $this->createAppointment('confirmed', 'imaging');

        $response = $this->postJson("/api/professional/appointments/{$appointment->id}/start");

        $response->assertOk()
            ->assertJsonPath('data.medical_record', null)
            ->assertJsonPath('data.exam.exam_type', 'imaging')
            ->assertJsonPath('data.exam.appointment_id', $appointment->id);

        $this->assertDatabaseMissing('medical_records', ['appointment_id' => $appointment->id]);
        $this->assertDatabaseHas('exams', [
            'appointment_id' => $appointment->id,
            'pet_id' => $this->pet->id,
            'professional_id' => $this->professional->id,
            'exam_type' => 'imaging',
        ]);
    }

    public function test_start_on_imaging_appointment_is_idempotent_and_reuses_the_same_exam(): void
    {
        $appointment = $this->createAppointment('confirmed', 'imaging');

        $first = $this->postJson("/api/professional/appointments/{$appointment->id}/start");
        $second = $this->postJson("/api/professional/appointments/{$appointment->id}/start");

        $first->assertOk();
        $second->assertOk();
        $this->assertSame($first->json('data.exam.id'), $second->json('data.exam.id'));
        $this->assertSame(1, Exam::where('appointment_id', $appointment->id)->count());
    }

    /**
     * Contrato §2 Grupo C: o mínimo para fechar o agendamento é o registro do ato
     * (`exam_type`+`exam_date`, já obrigatórios na criação) — nunca o diagnóstico. O `Exam`
     * continua sem laudo (nenhum `ExamResult`, `status` diferente de `completed`) e o
     * agendamento fecha do mesmo jeito.
     */
    public function test_close_without_finalizing_works_for_an_imaging_appointment_while_the_exam_has_no_results(): void
    {
        $appointment = $this->createAppointment('confirmed', 'imaging');
        $this->postJson("/api/professional/appointments/{$appointment->id}/start")->assertOk();

        $response = $this->postJson("/api/professional/appointments/{$appointment->id}/close-without-finalizing");

        $response->assertOk()->assertJsonPath('data.status', 'completed');
        $exam = Exam::where('appointment_id', $appointment->id)->firstOrFail();
        $this->assertNotSame('completed', $exam->status);
    }

    /**
     * Contrato §2/§3: `ensurePendingInvoice()` não depende do artefato clínico gerado — roda
     * igual para exame, consulta e banho. É o critério que resolve o pedido original: "o
     * mesmo fluxo para qualquer serviço". `exam_name` vem do serviço anexado, não do rótulo
     * genérico da categoria.
     */
    public function test_starting_an_imaging_appointment_with_a_service_still_launches_a_pending_invoice(): void
    {
        $appointment = $this->createAppointment('confirmed', 'imaging');
        $service = Service::create([
            'professional_id' => $this->professional->id,
            'name' => 'Ultrassom abdominal',
            'category' => 'imaging',
            'duration' => 30,
            'price' => 220,
            'active' => true,
        ]);
        $appointment->services()->create(['service_id' => $service->id, 'quantity' => 1, 'unit_price' => 220]);

        $response = $this->postJson("/api/professional/appointments/{$appointment->id}/start");

        $response->assertOk()->assertJsonPath('data.exam.exam_name', 'Ultrassom abdominal');
        $this->assertDatabaseHas('invoices', [
            'appointment_id' => $appointment->id,
            'status' => 'pending',
        ]);
    }

    /**
     * Contrato §2 Grupo A "condicional": nutrição só é ato clínico exclusivo de veterinário
     * quando é um veterinário quem executa (Res. CFMV 1.573/2023 x prática de mercado não
     * regulada para petshop).
     */
    public function test_nutrition_appointment_started_by_a_veterinarian_creates_a_medical_record(): void
    {
        $appointment = $this->createAppointment('confirmed', 'nutrition');

        $response = $this->postJson("/api/professional/appointments/{$appointment->id}/start");

        $response->assertOk()->assertJsonPath('data.medical_record.status', 'draft');
        $this->assertDatabaseHas('medical_records', ['appointment_id' => $appointment->id]);
    }

    public function test_nutrition_appointment_started_by_a_non_veterinarian_professional_creates_no_medical_record(): void
    {
        $petshopProfessional = $this->createNonVeterinarianProfessional();
        Sanctum::actingAs($petshopProfessional);

        $appointment = Appointment::create([
            'professional_id' => $petshopProfessional->id,
            'client_id' => $this->tutor->id,
            'pet_id' => $this->pet->id,
            'appointment_date' => now()->addDay()->startOfDay(),
            'appointment_time' => '10:00:00',
            'duration' => 30,
            'type' => 'nutrition',
            'status' => 'confirmed',
        ]);

        $response = $this->postJson("/api/professional/appointments/{$appointment->id}/start");

        $response->assertOk()
            ->assertJsonPath('data.medical_record', null)
            ->assertJsonPath('data.exam', null);
        $this->assertDatabaseMissing('medical_records', ['appointment_id' => $appointment->id]);
    }

    /**
     * Contrato docs/atendimento-veterinario/08-consulta-autorizada-por-agendamento.md §A: a
     * consulta supera a questão de acesso. Um profissional SEM `PetVetAccess` mas com um
     * agendamento confirmado para o pet pode iniciar o atendimento normalmente.
     */
    public function test_start_succeeds_without_pet_vet_access_when_appointment_is_confirmed(): void
    {
        $stranger = User::factory()->professional()->create();
        Sanctum::actingAs($stranger);

        $appointment = Appointment::create([
            'professional_id' => $stranger->id,
            'client_id' => $this->tutor->id,
            'pet_id' => $this->pet->id,
            'appointment_date' => now()->addDay()->startOfDay(),
            'appointment_time' => '10:00:00',
            'duration' => 30,
            'type' => 'consultation',
            'status' => 'confirmed',
        ]);

        $response = $this->postJson("/api/professional/appointments/{$appointment->id}/start");

        $response->assertOk()->assertJsonPath('data.medical_record.status', 'draft');
        $this->assertDatabaseHas('medical_records', [
            'appointment_id' => $appointment->id,
            'professional_id' => $stranger->id,
        ]);
    }

    /** O agendamento precisa pertencer ao profissional autenticado — não é sobre o pet. */
    public function test_start_returns_not_found_when_appointment_belongs_to_another_professional(): void
    {
        $stranger = User::factory()->professional()->create();
        $appointment = $this->createAppointment('confirmed');

        Sanctum::actingAs($stranger);

        $this->postJson("/api/professional/appointments/{$appointment->id}/start")->assertNotFound();
    }

    /**
     * @dataProvider unstartableStatusProvider
     */
    public function test_start_rejects_appointment_in_unstartable_status(string $status): void
    {
        $appointment = $this->createAppointment($status);

        $response = $this->postJson("/api/professional/appointments/{$appointment->id}/start");

        $response->assertStatus(422);
        $this->assertDatabaseMissing('medical_records', ['appointment_id' => $appointment->id]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unstartableStatusProvider(): array
    {
        return [
            'pending' => ['pending'],
            'cancelled' => ['cancelled'],
            'completed' => ['completed'],
            'no_show' => ['no_show'],
        ];
    }

    public function test_walk_in_creates_a_confirmed_appointment_already_in_progress(): void
    {
        $response = $this->postJson('/api/professional/appointments/walk-in', [
            'pet_id' => $this->pet->id,
            'type' => 'emergency',
            'reason' => 'Vômito e apatia',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.appointment.type', 'emergency')
            ->assertJsonPath('data.appointment.status', 'in_progress')
            ->assertJsonPath('data.medical_record.status', 'draft');

        $this->assertDatabaseHas('appointments', [
            'pet_id' => $this->pet->id,
            'client_id' => $this->tutor->id,
            'type' => 'emergency',
            'status' => 'in_progress',
        ]);
    }

    /** `client_id` nunca vem do payload — contrato §3. */
    public function test_walk_in_ignores_client_id_from_the_request_body(): void
    {
        $someoneElse = User::factory()->tutor()->create();

        $response = $this->postJson('/api/professional/appointments/walk-in', [
            'pet_id' => $this->pet->id,
            'client_id' => $someoneElse->id,
            'type' => 'emergency',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('appointments', [
            'pet_id' => $this->pet->id,
            'client_id' => $this->tutor->id,
        ]);
        $this->assertDatabaseMissing('appointments', ['client_id' => $someoneElse->id]);
    }

    /**
     * Contrato §A: o walk-in cria o PRÓPRIO agendamento, então não pode se autoautorizar por
     * status. Sem `PetVetAccess` e sem o tutor já ser cliente deste profissional, é negado.
     */
    public function test_walk_in_is_denied_when_the_tutor_is_not_yet_this_professionals_client(): void
    {
        $strangerVet = User::factory()->professional()->create();
        Sanctum::actingAs($strangerVet);

        $response = $this->postJson('/api/professional/appointments/walk-in', [
            'pet_id' => $this->pet->id,
            'type' => 'emergency',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('appointments', ['professional_id' => $strangerVet->id]);
    }

    public function test_close_without_finalizing_completes_appointment_but_keeps_draft(): void
    {
        $appointment = $this->createAppointment('confirmed');
        $this->postJson("/api/professional/appointments/{$appointment->id}/start")->assertOk();

        $response = $this->postJson("/api/professional/appointments/{$appointment->id}/close-without-finalizing");

        $response->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertSame('completed', $appointment->fresh()->status);
        $this->assertSame('draft', MedicalRecord::where('appointment_id', $appointment->id)->first()->status->value);
    }

    public function test_finalize_requires_diagnosis_or_treatment_plan_and_weight(): void
    {
        $record = $this->createDraftRecord();

        $response = $this->postJson("/api/professional/medical-records/{$record->id}/finalize");

        $response->assertStatus(422);
        $this->assertSame('draft', $record->fresh()->status->value);
    }

    /**
     * Vacinação pura não tem diagnóstico nem plano de tratamento. Exigi-los obrigaria o vet a
     * inventar texto só para fechar — campo clínico com lixo degrada o histórico inteiro do pet.
     */
    public function test_vaccination_encounter_finalizes_without_diagnosis_or_treatment_plan(): void
    {
        $record = $this->createDraftRecord('vaccination');
        $record->update(['weight' => 8.4]);

        $response = $this->postJson("/api/professional/medical-records/{$record->id}/finalize");

        $response->assertOk()->assertJsonPath('data.status', 'finalized');
        $this->assertTrue($record->fresh()->isFinalized());
    }

    /** O peso continua obrigatório mesmo na vacinação — é o que alimenta a curva do pet. */
    public function test_vaccination_encounter_still_requires_weight(): void
    {
        $record = $this->createDraftRecord('vaccination');

        $response = $this->postJson("/api/professional/medical-records/{$record->id}/finalize");

        $response->assertStatus(422);
        $this->assertSame('draft', $record->fresh()->status->value);
    }

    /**
     * A justificativa para o peso ser o único campo clínico obrigatório é alimentar
     * `PetWeightHistory`. Antes desta gravação a justificativa era falsa: a linha do tempo LIA
     * o histórico de peso, mas nada escrevia nele — toda pesagem de consulta se perdia.
     */
    public function test_finalize_records_the_measured_weight_in_pet_weight_history(): void
    {
        $record = $this->createDraftRecord();
        $record->update(['weight' => 12.3, 'diagnosis' => 'Gastroenterite aguda']);

        $this->postJson("/api/professional/medical-records/{$record->id}/finalize")->assertOk();

        $this->assertDatabaseHas('pet_weight_history', [
            'pet_id' => $this->pet->id,
            'measured_by_user_id' => $this->professional->id,
            'weight' => '12.30',
        ]);
    }

    /**
     * Contrato §13.5: a resposta do `finalize()` é onde o front, logo depois de fechar o
     * prontuário, abre o modal de pagamento — precisa achar a fatura sem inventar filtro
     * novo.
     */
    public function test_finalize_response_still_exposes_the_invoice_id(): void
    {
        $record = $this->createDraftRecord();
        $record->update(['weight' => 10, 'diagnosis' => 'Otite']);

        $this->postJson("/api/professional/appointments/{$record->appointment_id}/charges", [
            'description' => 'Consulta',
            'unit_price' => 150,
        ])->assertStatus(201);

        $invoice = Invoice::where('appointment_id', $record->appointment_id)->firstOrFail();

        $response = $this->postJson("/api/professional/medical-records/{$record->id}/finalize");

        $response->assertOk()->assertJsonPath('data.invoice_id', $invoice->id);
    }

    public function test_finalize_locks_the_record_and_can_schedule_a_follow_up(): void
    {
        $record = $this->createDraftRecord();
        $record->update(['weight' => 12.3, 'diagnosis' => 'Gastroenterite aguda']);

        $response = $this->postJson("/api/professional/medical-records/{$record->id}/finalize", [
            'follow_up' => ['date' => now()->addDays(15)->toDateTimeString(), 'reason' => 'reavaliação'],
        ]);

        $response->assertOk()->assertJsonPath('data.status', 'finalized');

        $fresh = $record->fresh();
        $this->assertTrue($fresh->isFinalized());
        $this->assertNotNull($fresh->finalized_at);
        $this->assertSame($this->professional->id, $fresh->finalized_by);
        $this->assertNotNull($fresh->follow_up_appointment_id);
        $this->assertDatabaseHas('appointments', [
            'id' => $fresh->follow_up_appointment_id,
            'pet_id' => $this->pet->id,
            'status' => 'scheduled',
        ]);
    }

    /**
     * Decisão do dono do produto: o retorno agendado é SEMPRE `consultation`, nunca herda o
     * `type` do atendimento de origem. Retorno pós-emergência é reavaliação, não outra
     * emergência — mesma regra vale para cirurgia e internação
     * (`HospitalizationDischargeDocumentTest`).
     */
    public function test_follow_up_is_always_a_consultation_regardless_of_the_source_appointment_type(): void
    {
        $record = $this->createDraftRecord('emergency');
        $record->update(['weight' => 8.5, 'diagnosis' => 'Intoxicação alimentar']);

        $this->postJson("/api/professional/medical-records/{$record->id}/finalize", [
            'follow_up' => ['date' => now()->addDays(3)->toDateTimeString(), 'reason' => 'reavaliação'],
        ])->assertOk();

        $fresh = $record->fresh();
        $this->assertDatabaseHas('appointments', [
            'id' => $fresh->follow_up_appointment_id,
            'type' => 'consultation',
        ]);
    }

    public function test_finalized_record_cannot_be_updated_or_deleted(): void
    {
        $record = $this->createDraftRecord();
        $record->update(['weight' => 10, 'diagnosis' => 'Otite']);
        $this->postJson("/api/professional/medical-records/{$record->id}/finalize")->assertOk();

        $this->putJson("/api/professional/medical-records/{$record->id}", ['notes' => 'tentando editar'])
            ->assertForbidden();
        $this->deleteJson("/api/professional/medical-records/{$record->id}")->assertForbidden();
    }

    /**
     * Contrato docs/atendimento-veterinario/03-contrato-receituario.md §1: toda `Prescription`
     * vinculada ao prontuário e ainda não emitida vira `issued_at = finalized_at`, no mesmo
     * instante e na mesma transação da finalização.
     */
    public function test_finalize_issues_all_unissued_prescriptions_linked_to_the_record(): void
    {
        $record = $this->createDraftRecord();
        $record->update(['weight' => 10, 'diagnosis' => 'Otite']);
        $prescription = $this->attachPrescription($record);

        $this->postJson("/api/professional/medical-records/{$record->id}/finalize")->assertOk();

        $fresh = $prescription->fresh();
        $this->assertNotNull($fresh->issued_at);
        $this->assertSame($record->fresh()->finalized_at->toDateTimeString(), $fresh->issued_at->toDateTimeString());
    }

    /**
     * Contrato §1: descartar um rascunho leva junto toda prescrição daquele atendimento ainda
     * não emitida — nunca deveria continuar visível uma receita de um atendimento que nunca
     * existiu.
     */
    public function test_discarding_a_draft_record_removes_its_unissued_prescriptions(): void
    {
        $record = $this->createDraftRecord();
        $prescription = $this->attachPrescription($record);

        $this->deleteJson("/api/professional/medical-records/{$record->id}")->assertOk();

        $this->assertNotNull(Prescription::withTrashed()->find($prescription->id)?->deleted_at);
    }

    private function attachPrescription(MedicalRecord $record): Prescription
    {
        $prescription = Prescription::create([
            'pet_id' => $record->pet_id,
            'professional_id' => $record->professional_id,
            'medical_record_id' => $record->id,
            'prescription_date' => now()->toDateString(),
        ]);

        $prescription->items()->create([
            'position' => 1,
            'commercial_name' => 'Amoxicilina',
            'dose_value' => 250,
            'dose_unit' => 'mg',
        ]);

        return $prescription;
    }

    /**
     * `isVeterinarian()` checa `User::VET_ROLES` via Spatie — `petshop_owner` é um
     * profissional legítimo da plataforma que não é veterinário, exatamente o caso que
     * distingue os dois ramos de `nutrition`/`behavioral`.
     */
    private function createNonVeterinarianProfessional(): User
    {
        $professional = User::factory()->professional()->create();
        $professional->syncRoles([]);
        $professional->assignRole(Role::findOrCreate('petshop_owner', 'web'));

        return $professional;
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

    private function createAppointment(string $status, string $type = 'consultation'): Appointment
    {
        return Appointment::create([
            'professional_id' => $this->professional->id,
            'client_id' => $this->tutor->id,
            'pet_id' => $this->pet->id,
            'appointment_date' => now()->addDay()->startOfDay(),
            'appointment_time' => '10:00:00',
            'duration' => 30,
            'type' => $type,
            'status' => $status,
        ]);
    }

    private function createDraftRecord(string $type = 'consultation'): MedicalRecord
    {
        $appointment = $this->createAppointment('confirmed', $type);
        $response = $this->postJson("/api/professional/appointments/{$appointment->id}/start");

        return MedicalRecord::findOrFail($response->json('data.medical_record.id'));
    }
}
