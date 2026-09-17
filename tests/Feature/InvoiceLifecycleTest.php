<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Payment;
use App\Models\Pet;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Ciclo de vida da fatura (`issue`/`cancel`/`mark-as-paid`) e a matriz de autorização
 * vet volante × clínica — contrato
 * docs/atendimento-veterinario/09-faturamento-do-atendimento.md §4/§5/§11 item 4.
 *
 * Escrito conforme a regra do projeto: teste escrito, NÃO executado via `artisan test`.
 * Fluxo validado manualmente via `curl`/`tinker` contra a API em execução (ver relatório).
 */
class InvoiceLifecycleTest extends TestCase
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
    }

    public function test_author_can_issue_a_draft_invoice(): void
    {
        $invoice = $this->makeInvoice('draft');
        Sanctum::actingAs($this->vet);

        $response = $this->postJson("/api/professional/invoices/{$invoice->id}/issue");

        $response->assertOk()->assertJsonPath('data.status', 'pending');
    }

    public function test_issue_fails_with_no_items(): void
    {
        $invoice = $this->makeInvoice('draft', items: []);
        Sanctum::actingAs($this->vet);

        $response = $this->postJson("/api/professional/invoices/{$invoice->id}/issue");

        $response->assertStatus(422);
        $this->assertSame('draft', $invoice->fresh()->status);
    }

    /** Reemitir a mesma fatura já `pending` é idempotente — mesma filosofia de `AppointmentStatus::canTransitionTo`. */
    public function test_issuing_an_already_pending_invoice_is_idempotent(): void
    {
        $invoice = $this->makeInvoice('draft');
        Sanctum::actingAs($this->vet);

        $this->postJson("/api/professional/invoices/{$invoice->id}/issue")->assertOk();
        $response = $this->postJson("/api/professional/invoices/{$invoice->id}/issue");

        $response->assertOk()->assertJsonPath('data.status', 'pending');
    }

    public function test_cannot_issue_a_cancelled_invoice(): void
    {
        $invoice = $this->makeInvoice('cancelled');
        Sanctum::actingAs($this->vet);

        $response = $this->postJson("/api/professional/invoices/{$invoice->id}/issue");

        $response->assertStatus(422);
    }

    /**
     * Contrato §13.5/§13.7: `issue()` só existe para fatura MANUAL — fatura originada de
     * atendimento já nasce `pending` ao iniciar, não há rascunho para emitir.
     */
    public function test_cannot_issue_an_invoice_originated_from_an_appointment(): void
    {
        $invoice = $this->makeInvoiceForPet('pending');
        Sanctum::actingAs($this->vet);

        $response = $this->postJson("/api/professional/invoices/{$invoice->id}/issue");

        $response->assertStatus(422);
    }

    public function test_a_stranger_professional_cannot_issue_someone_elses_invoice(): void
    {
        $invoice = $this->makeInvoice('draft');
        $stranger = User::factory()->professional()->create();
        Sanctum::actingAs($stranger);

        $response = $this->postJson("/api/professional/invoices/{$invoice->id}/issue");

        $response->assertStatus(403);
    }

    public function test_author_can_cancel_a_pending_invoice_with_a_reason(): void
    {
        $invoice = $this->makeInvoice('pending');
        Sanctum::actingAs($this->vet);

        $response = $this->postJson("/api/professional/invoices/{$invoice->id}/cancel", [
            'reason' => 'Atendimento cortesia',
        ]);

        $response->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertStringContainsString('Atendimento cortesia', $invoice->fresh()->notes ?? '');
    }

    public function test_cannot_cancel_an_already_paid_invoice(): void
    {
        $invoice = $this->makeInvoice('paid');
        Sanctum::actingAs($this->vet);

        $response = $this->postJson("/api/professional/invoices/{$invoice->id}/cancel");

        $response->assertStatus(422);
    }

    /**
     * Contrato §6: pagamento presencial é declaração auditável do profissional, nunca
     * autodeclarada pelo tutor.
     */
    public function test_tutor_cannot_mark_own_invoice_as_paid(): void
    {
        $invoice = $this->makeInvoice('pending');
        Sanctum::actingAs($this->tutor);

        $response = $this->postJson("/api/professional/invoices/{$invoice->id}/mark-as-paid", [
            'method' => 'cash',
        ]);

        $response->assertStatus(403);
    }

    public function test_author_marks_invoice_as_paid_with_cash_and_records_manual_channel(): void
    {
        $invoice = $this->makeInvoice('pending');
        Sanctum::actingAs($this->vet);

        $response = $this->postJson("/api/professional/invoices/{$invoice->id}/mark-as-paid", [
            'method' => 'cash',
            'notes' => 'Recebido no balcão',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.payment_method', 'cash')
            ->assertJsonPath('data.payment_channel', 'manual_offline');

        $this->assertDatabaseHas('payments', [
            'invoice_id' => $invoice->id,
            'gateway' => 'manual',
            'method' => 'cash',
            'status' => 'paid',
        ]);
    }

    /**
     * `PaymentService::markInvoiceAsPaidManually` reaproveita o `Payment` `pending` já
     * vinculado à fatura (ex.: uma tentativa de PIX que não foi concluída) em vez de criar
     * uma segunda linha para o mesmo lançamento — era isso que duplicava `Payment` a cada
     * `mark-as-paid`.
     */
    public function test_mark_as_paid_reuses_an_existing_pending_payment_instead_of_duplicating(): void
    {
        $invoice = $this->makeInvoice('pending');
        $pendingPayment = Payment::create([
            'invoice_id' => $invoice->id,
            'user_id' => $this->tutor->id,
            'gateway' => 'mercadopago',
            'gateway_payment_id' => 'mp-123',
            'method' => 'pix',
            'amount' => $invoice->total,
            'status' => 'pending',
        ]);
        Sanctum::actingAs($this->vet);

        $response = $this->postJson("/api/professional/invoices/{$invoice->id}/mark-as-paid", [
            'method' => 'cash',
            'notes' => 'Pago no balcão',
        ]);

        $response->assertOk()->assertJsonPath('data.status', 'paid');
        $this->assertSame(1, Payment::where('invoice_id', $invoice->id)->count());

        $reused = $pendingPayment->fresh();
        $this->assertSame('manual', $reused->gateway);
        $this->assertSame('cash', $reused->method);
        $this->assertSame('paid', $reused->status);
        $this->assertNotNull($reused->paid_at);
    }

    /** Mais de um `Payment` não finalizado vinculado à fatura: reaproveita o mais recente. */
    public function test_mark_as_paid_reuses_the_most_recent_non_final_payment(): void
    {
        $invoice = $this->makeInvoice('pending');
        $older = $this->makePaymentAt($invoice, 'failed', now()->subMinutes(10));
        $newer = $this->makePaymentAt($invoice, 'pending', now());
        Sanctum::actingAs($this->vet);

        $this->postJson("/api/professional/invoices/{$invoice->id}/mark-as-paid", ['method' => 'cash'])
            ->assertOk();

        $this->assertSame(2, Payment::where('invoice_id', $invoice->id)->count());
        $this->assertSame('paid', $newer->fresh()->status);
        $this->assertSame('failed', $older->fresh()->status);
    }

    /**
     * `InvoiceStatus::canTransitionTo` permite o self-transition `paid → paid` (contrato
     * §2.1) — sozinha, a trava de `assertTransition` NÃO bloqueia um segundo
     * `mark-as-paid` sobre uma fatura já quitada. Precisa falhar explícito em vez de
     * reaproveitar ou duplicar o `Payment` `paid` que já existe.
     */
    public function test_mark_as_paid_fails_explicitly_when_a_paid_payment_already_exists(): void
    {
        $invoice = $this->makeInvoice('pending');
        Sanctum::actingAs($this->vet);
        $this->postJson("/api/professional/invoices/{$invoice->id}/mark-as-paid", ['method' => 'cash'])
            ->assertOk();

        $response = $this->postJson("/api/professional/invoices/{$invoice->id}/mark-as-paid", ['method' => 'cash']);

        $response->assertStatus(422);
        $this->assertSame(1, Payment::where('invoice_id', $invoice->id)->count());
    }

    /**
     * Complemento ao contrato: "nenhum serviço fica de fora" — banho e tosa não tem
     * `MedicalRecord`, mas a fatura e a autorização de recebimento seguem o mesmo caminho
     * (via `Invoice`, nunca via prontuário) de qualquer outro tipo de atendimento.
     */
    public function test_author_marks_a_grooming_invoice_as_paid_with_no_medical_record_involved(): void
    {
        $invoice = $this->makeInvoiceForPet('pending', type: 'grooming');
        Sanctum::actingAs($this->vet);

        $response = $this->postJson("/api/professional/invoices/{$invoice->id}/mark-as-paid", [
            'method' => 'cash',
        ]);

        $response->assertOk()->assertJsonPath('data.status', 'paid');
    }

    private function makePaymentAt(Invoice $invoice, string $status, Carbon $createdAt): Payment
    {
        $payment = Payment::create([
            'invoice_id' => $invoice->id,
            'user_id' => $this->tutor->id,
            'gateway' => 'mercadopago',
            'method' => 'pix',
            'amount' => $invoice->total,
            'status' => $status,
        ]);

        $payment->forceFill(['created_at' => $createdAt])->save();

        return $payment;
    }

    /** Contrato §5: front desk (staff comum) cobra, mesmo sem ter atendido. */
    public function test_a_colleague_from_the_same_organization_can_receive_the_payment(): void
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

        $invoice = $this->makeInvoice('pending', organizationId: $organization->id);
        Sanctum::actingAs($receptionist);

        $response = $this->postJson("/api/professional/invoices/{$invoice->id}/mark-as-paid", [
            'method' => 'debit_card',
        ]);

        $response->assertOk()->assertJsonPath('data.status', 'paid');
    }

    /** Invariante 2: rascunho é invisível ao tutor em qualquer rota. */
    public function test_draft_invoice_is_invisible_to_the_tutor(): void
    {
        $invoice = $this->makeInvoiceForPet('draft');
        Sanctum::actingAs($this->tutor);

        $this->getJson("/api/invoices/{$invoice->id}")->assertStatus(403);

        $this->getJson("/api/pets/{$this->pet->id}/invoices")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_tutor_can_read_the_invoice_once_it_is_issued(): void
    {
        $invoice = $this->makeInvoiceForPet('pending');
        Sanctum::actingAs($this->tutor);

        $this->getJson("/api/invoices/{$invoice->id}")->assertOk()->assertJsonPath('data.status', 'pending');

        $this->getJson("/api/pets/{$this->pet->id}/invoices")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_a_different_tutor_cannot_read_this_pets_invoices(): void
    {
        $this->makeInvoiceForPet('pending');
        $anotherTutor = User::factory()->tutor()->create();
        Sanctum::actingAs($anotherTutor);

        $this->getJson("/api/pets/{$this->pet->id}/invoices")->assertStatus(403);
    }

    public function test_tutor_cannot_update_or_delete_an_invoice(): void
    {
        $invoice = $this->makeInvoice('draft');
        Sanctum::actingAs($this->tutor);

        $this->putJson("/api/professional/invoices/{$invoice->id}", ['discount' => 10])->assertStatus(403);
        $this->deleteJson("/api/professional/invoices/{$invoice->id}")->assertStatus(403);
    }

    /**
     * Fatura MANUAL (`appointment_id = null`): continua sob a invariante 8, inalterada
     * para esse caso — editável só em `draft`, travada assim que sai dele.
     */
    public function test_cannot_update_a_pending_manual_invoice_even_as_the_author(): void
    {
        $invoice = $this->makeInvoice('pending');
        Sanctum::actingAs($this->vet);

        $response = $this->putJson("/api/professional/invoices/{$invoice->id}", ['discount' => 10]);

        $response->assertStatus(403);
    }

    /**
     * Fatura DE ATENDIMENTO (`appointment_id` presente): contrato §13.5/§13.8 invariante
     * 13 — nasce `pending` direto, itens congelam no pagamento, não na emissão. Regressão
     * do bug relatado ao vivo: `PUT` numa fatura `pending` de atendimento devolvia 403
     * sempre, porque a policy ainda checava só `draft` (regra pré-§13).
     */
    public function test_author_can_update_a_pending_appointment_invoice(): void
    {
        $invoice = $this->makeInvoiceForPet('pending');
        Sanctum::actingAs($this->vet);

        $response = $this->putJson("/api/professional/invoices/{$invoice->id}", ['discount' => 10]);

        $response->assertOk()->assertJsonPath('data.discount', 10);
    }

    /** Dono da organização tem a mesma autoridade do autor sobre a fatura da clínica. */
    public function test_clinic_owner_can_update_a_pending_appointment_invoice_of_another_vet(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->professional()->create();
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $this->vet->id,
            'role' => OrganizationMember::ROLE_VETERINARIAN,
        ]);
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
            'role' => OrganizationMember::ROLE_OWNER,
        ]);

        $invoice = $this->makeInvoiceForPet('pending');
        $invoice->update(['organization_id' => $organization->id]);
        Sanctum::actingAs($owner);

        $response = $this->putJson("/api/professional/invoices/{$invoice->id}", ['discount' => 5]);

        $response->assertOk()->assertJsonPath('data.discount', 5);
    }

    /**
     * Front desk cobra, não decide o que foi cobrado (contrato §5) — a mesma separação
     * que já valia para `draft` se estende sem mudança para a janela `pending` de
     * atendimento: um colega comum da organização (sem ser autor nem dono) só recebe
     * pagamento, nunca edita itens.
     */
    public function test_a_colleague_from_the_same_organization_cannot_edit_a_pending_appointment_invoice(): void
    {
        $organization = Organization::factory()->create();
        $receptionist = User::factory()->professional()->create();
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $this->vet->id,
            'role' => OrganizationMember::ROLE_VETERINARIAN,
        ]);
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $receptionist->id,
            'role' => OrganizationMember::ROLE_RECEPTIONIST,
        ]);

        $invoice = $this->makeInvoiceForPet('pending');
        $invoice->update(['organization_id' => $organization->id]);
        Sanctum::actingAs($receptionist);

        $response = $this->putJson("/api/professional/invoices/{$invoice->id}", ['discount' => 5]);

        $response->assertStatus(403);
    }

    /** `paid`/`cancelled`/`refunded` nunca é editável, mesmo para fatura de atendimento. */
    public function test_cannot_update_a_paid_appointment_invoice_even_as_the_author(): void
    {
        $invoice = $this->makeInvoiceForPet('paid');
        Sanctum::actingAs($this->vet);

        $response = $this->putJson("/api/professional/invoices/{$invoice->id}", ['discount' => 10]);

        $response->assertStatus(403);
    }

    /**
     * `destroy` continua mais restrito que `update`: fatura de atendimento nunca é
     * apagável, mesmo `pending` e mesmo pelo autor. Ela nunca esteve em `draft` (§13.5) —
     * o caminho para descartar é `cancel()` (auditável, com motivo), não `DELETE`.
     */
    public function test_author_cannot_delete_a_pending_appointment_invoice(): void
    {
        $invoice = $this->makeInvoiceForPet('pending');
        Sanctum::actingAs($this->vet);

        $response = $this->deleteJson("/api/professional/invoices/{$invoice->id}");

        $response->assertStatus(403);
    }

    /** Fatura ligada a `$this->pet` via `appointment_id` — mesmo caminho que `PetInvoicesController` segue. */
    private function makeInvoiceForPet(string $status, string $type = 'consultation'): Invoice
    {
        $appointment = Appointment::create([
            'professional_id' => $this->vet->id,
            'client_id' => $this->tutor->id,
            'pet_id' => $this->pet->id,
            'appointment_date' => now()->toDateString(),
            'appointment_time' => '09:00',
            'type' => $type,
            'status' => 'completed',
        ]);

        $invoice = $this->makeInvoice($status);
        $invoice->update(['appointment_id' => $appointment->id]);

        return $invoice;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function makeInvoice(string $status, array $items = [], ?int $organizationId = null): Invoice
    {
        $items = $items ?: [['description' => 'Consulta', 'quantity' => 1, 'unit_price' => 150, 'total' => 150]];

        return Invoice::create([
            'professional_id' => $this->vet->id,
            'organization_id' => $organizationId,
            'client_id' => $this->tutor->id,
            'invoice_number' => 'INV-'.uniqid(),
            'issue_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'items' => $items,
            'subtotal' => 150,
            'total' => 150,
            'status' => $status,
        ]);
    }
}
