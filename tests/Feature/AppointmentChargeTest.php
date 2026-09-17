<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Pet;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Linhas de cobrança do atendimento — contrato
 * docs/atendimento-veterinario/09-faturamento-do-atendimento.md §13.3/§13.5/§13.7.
 *
 * MOVEU de `MedicalRecordChargeTest`/`medical-records/{id}/charges` (§13.3): a comanda
 * agora pendura no AGENDAMENTO, não mais no prontuário — banho e tosa não gera
 * `MedicalRecord` e precisa lançar cobrança do mesmo jeito.
 *
 * Escrito conforme a regra do projeto: teste escrito, NÃO executado via `artisan test`.
 * Fluxo validado manualmente via `curl`/`tinker` contra a API em execução (ver relatório).
 */
class AppointmentChargeTest extends TestCase
{
    use RefreshDatabase;

    private User $vet;

    private User $tutor;

    private Pet $pet;

    private Appointment $appointment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vet = User::factory()->professional()->create();
        $this->tutor = User::factory()->tutor()->create();
        $this->pet = Pet::factory()->create(['user_id' => $this->tutor->id]);
        $this->appointment = $this->makeAppointment('in_progress');
    }

    public function test_author_can_create_and_list_a_charge_line(): void
    {
        Sanctum::actingAs($this->vet);

        $response = $this->postJson("/api/professional/appointments/{$this->appointment->id}/charges", [
            'description' => 'Consulta',
            'unit_price' => 150,
        ]);

        $response->assertStatus(201)->assertJsonPath('data.description', 'Consulta');

        $this->getJson("/api/professional/appointments/{$this->appointment->id}/charges")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /** Contrato §13.7: sem `description`/`unit_price`, o servidor preenche do catálogo. */
    public function test_charge_fills_description_and_price_from_catalog_when_only_service_id_is_sent(): void
    {
        Sanctum::actingAs($this->vet);

        $service = Service::create([
            'professional_id' => $this->vet->id,
            'name' => 'Vacina V10',
            'category' => 'vaccination',
            'duration' => 15,
            'price' => 120,
            'active' => true,
        ]);

        $response = $this->postJson("/api/professional/appointments/{$this->appointment->id}/charges", [
            'service_id' => $service->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.description', 'Vacina V10')
            ->assertJsonPath('data.unit_price', 120);
    }

    public function test_charge_without_service_id_requires_description_and_price(): void
    {
        Sanctum::actingAs($this->vet);

        $response = $this->postJson("/api/professional/appointments/{$this->appointment->id}/charges", []);

        $response->assertStatus(422)->assertJsonValidationErrors(['description', 'unit_price']);
    }

    /**
     * Contrato §13.1/§13.5: sem serviço nenhum no agendamento, nada é lançado ao iniciar
     * (invariante 11) — mas a PRIMEIRA linha manual cria a fatura `pending` sob demanda.
     */
    public function test_first_charge_on_an_appointment_without_services_creates_the_pending_invoice(): void
    {
        $this->assertSame(0, Invoice::where('appointment_id', $this->appointment->id)->count());
        Sanctum::actingAs($this->vet);

        $this->postJson("/api/professional/appointments/{$this->appointment->id}/charges", [
            'description' => 'Consulta avulsa',
            'unit_price' => 200,
        ])->assertStatus(201);

        $invoice = Invoice::where('appointment_id', $this->appointment->id)->first();

        $this->assertNotNull($invoice);
        $this->assertSame('pending', $invoice->status);
        $this->assertEqualsWithDelta(200.0, (float) $invoice->total, 0.001);
    }

    /** Contrato §13.5: itens acrescentados enquanto a fatura não foi paga entram na mesma fatura. */
    public function test_a_second_charge_is_added_to_the_same_pending_invoice(): void
    {
        Sanctum::actingAs($this->vet);

        $this->postJson("/api/professional/appointments/{$this->appointment->id}/charges", [
            'description' => 'Consulta', 'unit_price' => 150,
        ])->assertStatus(201);
        $this->postJson("/api/professional/appointments/{$this->appointment->id}/charges", [
            'description' => 'Vacina V10', 'unit_price' => 120,
        ])->assertStatus(201);

        $invoices = Invoice::where('appointment_id', $this->appointment->id)->get();

        $this->assertCount(1, $invoices);
        $this->assertEqualsWithDelta(270.0, (float) $invoices->first()->total, 0.001);
    }

    public function test_cannot_change_a_charge_line_once_the_appointment_is_completed(): void
    {
        Sanctum::actingAs($this->vet);
        $this->appointment->update(['status' => 'completed']);

        $response = $this->postJson("/api/professional/appointments/{$this->appointment->id}/charges", [
            'description' => 'Tarde demais',
            'unit_price' => 10,
        ]);

        $response->assertStatus(422);
    }

    /** Contrato invariante 13: itens congelam no PAGAMENTO, não na emissão. */
    public function test_cannot_change_a_charge_line_once_the_invoice_is_paid(): void
    {
        Invoice::create($this->invoiceAttributes('paid'));
        Sanctum::actingAs($this->vet);

        $response = $this->postJson("/api/professional/appointments/{$this->appointment->id}/charges", [
            'description' => 'Tarde demais',
            'unit_price' => 10,
        ]);

        $response->assertStatus(422);
    }

    public function test_clinic_owner_can_manage_a_colleague_veterinarians_charges(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->professional()->create();
        OrganizationMember::factory()->owner()->create([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
        ]);
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $this->vet->id,
        ]);

        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/professional/appointments/{$this->appointment->id}/charges", [
            'description' => 'Consulta',
            'unit_price' => 150,
        ]);

        $response->assertStatus(201);
    }

    /**
     * Contrato §5: front desk (staff comum da mesma organização) NÃO decide o que está
     * sendo cobrado — só o autor ou o dono da organização editam a comanda em aberto.
     */
    public function test_a_regular_staff_colleague_cannot_manage_the_charges(): void
    {
        $organization = Organization::factory()->create();
        $receptionist = User::factory()->professional()->create();
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $this->vet->id,
        ]);
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $receptionist->id,
            'role' => OrganizationMember::ROLE_RECEPTIONIST,
        ]);

        Sanctum::actingAs($receptionist);

        $response = $this->postJson("/api/professional/appointments/{$this->appointment->id}/charges", [
            'description' => 'Consulta',
            'unit_price' => 150,
        ]);

        $response->assertStatus(403);
    }

    public function test_an_unrelated_professional_cannot_manage_the_charges(): void
    {
        $stranger = User::factory()->professional()->create();
        Sanctum::actingAs($stranger);

        $response = $this->postJson("/api/professional/appointments/{$this->appointment->id}/charges", [
            'description' => 'Consulta',
            'unit_price' => 150,
        ]);

        $response->assertStatus(403);
    }

    public function test_the_tutor_cannot_manage_the_charges(): void
    {
        Sanctum::actingAs($this->tutor);

        $response = $this->postJson("/api/professional/appointments/{$this->appointment->id}/charges", [
            'description' => 'Consulta',
            'unit_price' => 150,
        ]);

        $response->assertStatus(403);
    }

    private function makeAppointment(string $status): Appointment
    {
        return Appointment::create([
            'professional_id' => $this->vet->id,
            'client_id' => $this->tutor->id,
            'pet_id' => $this->pet->id,
            'appointment_date' => now()->toDateString(),
            'appointment_time' => '09:00',
            'type' => 'consultation',
            'status' => $status,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceAttributes(string $status): array
    {
        return [
            'professional_id' => $this->vet->id,
            'client_id' => $this->tutor->id,
            'appointment_id' => $this->appointment->id,
            'invoice_number' => 'INV-'.uniqid(),
            'issue_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'items' => [['description' => 'Consulta', 'quantity' => 1, 'unit_price' => 150, 'total' => 150]],
            'subtotal' => 150,
            'total' => 150,
            'status' => $status,
        ];
    }
}
