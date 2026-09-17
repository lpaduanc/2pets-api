<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\MedicalRecord;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Pet;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Faturamento do atendimento — contrato
 * docs/atendimento-veterinario/09-faturamento-do-atendimento.md §13.1/§13.4/§13.5.
 *
 * REESCRITO (2026-09-16): o modelo anterior selava `medical_record_charges` numa
 * `Invoice` rascunho em `finalize()`. O dono do produto revisou o fluxo — agora a
 * fatura nasce `pending` automaticamente ao INICIAR o atendimento
 * (`ConsultationService::start()` → `AppointmentInvoiceService::ensurePendingInvoice`),
 * a partir dos serviços do agendamento (`AppointmentService`). `finalize()` não toca
 * mais em faturamento nenhum — este arquivo agora prova isso, não o contrário.
 *
 * Escrito conforme a regra do projeto: teste escrito, NÃO executado via `artisan test`.
 * Fluxo validado manualmente via `curl`/`tinker` contra a API em execução (ver relatório).
 */
class MedicalRecordFinalizationInvoiceTest extends TestCase
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

        Sanctum::actingAs($this->vet);
    }

    public function test_starting_an_appointment_with_services_creates_a_pending_invoice_automatically(): void
    {
        $consultation = $this->makeService('Consulta geral', 150);
        $vaccine = $this->makeService('Vacina V10', 120);
        $appointment = $this->makeAppointment('consultation', [$consultation, $vaccine]);

        $this->postJson("/api/professional/appointments/{$appointment->id}/start")->assertOk();

        $invoice = Invoice::where('appointment_id', $appointment->id)->first();

        $this->assertNotNull($invoice);
        $this->assertSame('pending', $invoice->status);
        $this->assertEqualsWithDelta(270.0, (float) $invoice->total, 0.001);
        // Invariante 7: o pagador é sempre o dono do pet, nunca aceito de payload.
        $this->assertSame($this->tutor->id, $invoice->client_id);
        $this->assertSame($this->vet->id, $invoice->professional_id);
    }

    /** Invariante 11: sem serviço nenhum no agendamento, iniciar não lança nada. */
    public function test_starting_an_appointment_without_any_service_creates_no_invoice(): void
    {
        $appointment = $this->makeAppointment('consultation', []);

        $this->postJson("/api/professional/appointments/{$appointment->id}/start")->assertOk();

        $this->assertSame(0, Invoice::where('appointment_id', $appointment->id)->count());
    }

    /**
     * Contrato §13.5: `finalize()` não cria, edita nem toca a fatura do atendimento — ela
     * já nasceu `pending` em `start()`. Regressão do modelo anterior (que criava a fatura
     * só em `finalize()`).
     */
    public function test_finalizing_the_medical_record_does_not_touch_the_invoice(): void
    {
        $service = $this->makeService('Consulta geral', 150);
        $appointment = $this->makeAppointment('consultation', [$service]);
        $this->postJson("/api/professional/appointments/{$appointment->id}/start")->assertOk();

        $record = MedicalRecord::where('appointment_id', $appointment->id)->firstOrFail();
        $invoiceBefore = Invoice::where('appointment_id', $appointment->id)->firstOrFail();

        $record->update(['weight' => 4.0, 'diagnosis' => 'Rotina']);
        $this->postJson("/api/professional/medical-records/{$record->id}/finalize")->assertOk();

        $this->assertSame(1, Invoice::where('appointment_id', $appointment->id)->count());
        $invoiceAfter = $invoiceBefore->fresh();
        $this->assertSame('pending', $invoiceAfter->status);
        $this->assertEqualsWithDelta((float) $invoiceBefore->total, (float) $invoiceAfter->total, 0.001);
    }

    /** Contrato §13.4: banho e tosa não gera prontuário, mas lança fatura igual. */
    public function test_starting_a_grooming_appointment_creates_no_medical_record_but_creates_the_invoice(): void
    {
        $service = $this->makeService('Banho e tosa completo', 90);
        $appointment = $this->makeAppointment('grooming', [$service]);

        $response = $this->postJson("/api/professional/appointments/{$appointment->id}/start");

        $response->assertOk()->assertJsonPath('data.medical_record', null);
        $this->assertSame(0, MedicalRecord::where('appointment_id', $appointment->id)->count());

        $invoice = Invoice::where('appointment_id', $appointment->id)->first();
        $this->assertNotNull($invoice);
        $this->assertSame('pending', $invoice->status);
        $this->assertEqualsWithDelta(90.0, (float) $invoice->total, 0.001);
    }

    public function test_starting_fills_organization_id_for_a_clinic_veterinarian(): void
    {
        $organization = Organization::factory()->create();
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $this->vet->id,
        ]);

        $service = $this->makeService('Consulta geral', 150);
        $appointment = $this->makeAppointment('consultation', [$service]);

        $this->postJson("/api/professional/appointments/{$appointment->id}/start")->assertOk();

        $invoice = Invoice::where('appointment_id', $appointment->id)->first();

        $this->assertSame($organization->id, $invoice->organization_id);
    }

    private function makeService(string $name, float $price): Service
    {
        return Service::create([
            'professional_id' => $this->vet->id,
            'name' => $name,
            'category' => 'consultation',
            'duration' => 30,
            'price' => $price,
            'active' => true,
        ]);
    }

    /**
     * @param  array<int, Service>  $services
     */
    private function makeAppointment(string $type, array $services): Appointment
    {
        $appointment = Appointment::create([
            'professional_id' => $this->vet->id,
            'client_id' => $this->tutor->id,
            'pet_id' => $this->pet->id,
            'appointment_date' => now()->toDateString(),
            'appointment_time' => '09:00',
            'type' => $type,
            'status' => 'confirmed',
        ]);

        foreach ($services as $service) {
            $appointment->services()->create([
                'service_id' => $service->id,
                'quantity' => 1,
                'unit_price' => $service->price,
            ]);
        }

        return $appointment;
    }
}
