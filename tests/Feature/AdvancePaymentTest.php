<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Pet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pagamento adiantado — restrito à internação. Contrato
 * docs/atendimento-veterinario/11-internacao-no-fluxo-de-faturamento.md §3-bis.
 *
 * Escrito conforme a regra do projeto: teste escrito, NÃO executado via `artisan test`.
 */
class AdvancePaymentTest extends TestCase
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

    /** §3-bis.4: a restrição é checada no service, não só escondida na UI. */
    public function test_advance_payment_is_rejected_for_a_non_hospitalization_invoice(): void
    {
        $invoice = $this->makeInvoice('consultation', 300);

        $response = $this->postJson("/api/professional/invoices/{$invoice->id}/advance-payment", [
            'method' => 'pix',
            'amount' => 100,
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Payment::count());
    }

    public function test_advance_payment_is_accepted_for_a_hospitalization_invoice_and_keeps_it_pending(): void
    {
        $invoice = $this->makeInvoice('hospitalization', 1000);

        $response = $this->postJson("/api/professional/invoices/{$invoice->id}/advance-payment", [
            'method' => 'pix',
            'amount' => 300,
        ]);

        $response->assertOk()->assertJsonPath('data.amount_paid', 300.0);
        $payment = Payment::where('invoice_id', $invoice->id)->where('purpose', 'advance')->firstOrFail();
        $this->assertSame('paid', $payment->status);
        $this->assertEqualsWithDelta(300.0, (float) $payment->amount, 0.001);
        $this->assertSame('pending', $invoice->fresh()->status);
    }

    /**
     * §3-bis.2: a trava de duplicidade só olha `purpose = settlement` — dois adiantamentos
     * seguidos nunca disparam `InconsistentInvoicePaymentStateException`.
     */
    public function test_two_advance_payments_create_two_distinct_paid_payments(): void
    {
        $invoice = $this->makeInvoice('hospitalization', 1000);

        $this->postJson("/api/professional/invoices/{$invoice->id}/advance-payment", ['method' => 'pix', 'amount' => 200])
            ->assertOk();
        $this->postJson("/api/professional/invoices/{$invoice->id}/advance-payment", ['method' => 'cash', 'amount' => 150])
            ->assertOk();

        $this->assertSame(2, Payment::where('invoice_id', $invoice->id)->where('purpose', 'advance')->where('status', 'paid')->count());
        $this->assertSame('pending', $invoice->fresh()->status);
    }

    /** Invariante 13 preservada: adiantamento não congela a conta. */
    public function test_new_charges_are_still_accepted_after_advance_payments(): void
    {
        $appointment = $this->makeHospitalizationAppointment();
        $invoice = $this->makeInvoiceForAppointment($appointment, 400);

        $this->postJson("/api/professional/invoices/{$invoice->id}/advance-payment", ['method' => 'pix', 'amount' => 200])
            ->assertOk();

        $response = $this->postJson("/api/professional/appointments/{$appointment->id}/charges", [
            'description' => 'Diária extra',
            'unit_price' => 200,
        ]);

        $response->assertStatus(201);
        $this->assertSame('pending', $invoice->fresh()->status);
    }

    public function test_invoice_exposes_amount_paid_balance_due_and_credit_balance(): void
    {
        $invoice = $this->makeInvoice('hospitalization', 500);
        $this->postJson("/api/professional/invoices/{$invoice->id}/advance-payment", ['method' => 'pix', 'amount' => 350])
            ->assertOk();

        $response = $this->getJson("/api/professional/invoices/{$invoice->id}");

        $response->assertOk()
            ->assertJsonPath('data.amount_paid', 350.0)
            ->assertJsonPath('data.balance_due', 150.0)
            ->assertJsonPath('data.credit_balance', 0.0);
    }

    /** §3-bis.3: o acerto final cobra o que FALTA, não o total inteiro de novo. */
    public function test_settlement_charges_the_remaining_balance_when_advances_exist(): void
    {
        $invoice = $this->makeInvoice('hospitalization', 500);
        $this->postJson("/api/professional/invoices/{$invoice->id}/advance-payment", ['method' => 'pix', 'amount' => 350])
            ->assertOk();

        $response = $this->postJson("/api/professional/invoices/{$invoice->id}/mark-as-paid", ['method' => 'cash']);

        $response->assertOk()->assertJsonPath('data.status', 'paid');
        $settlement = Payment::where('invoice_id', $invoice->id)->where('purpose', 'settlement')->firstOrFail();
        $this->assertSame('paid', $settlement->status);
        $this->assertEqualsWithDelta(150.0, (float) $settlement->amount, 0.001);
    }

    /** Sem adiantamento nenhum, o comportamento continua idêntico ao de antes desta tarefa. */
    public function test_settlement_charges_the_full_total_when_there_was_no_advance(): void
    {
        $invoice = $this->makeInvoice('consultation', 300);

        $this->postJson("/api/professional/invoices/{$invoice->id}/mark-as-paid", ['method' => 'cash'])
            ->assertOk();

        $settlement = Payment::where('invoice_id', $invoice->id)->where('purpose', 'settlement')->firstOrFail();
        $this->assertEqualsWithDelta(300.0, (float) $settlement->amount, 0.001);
    }

    /** §3-bis.3: sobra de adiantamento exige declaração explícita, nunca devolução automática. */
    public function test_settlement_with_a_credit_balance_requires_credit_resolution(): void
    {
        $invoice = $this->makeInvoice('hospitalization', 300);
        $this->postJson("/api/professional/invoices/{$invoice->id}/advance-payment", ['method' => 'pix', 'amount' => 400])
            ->assertOk();

        $rejected = $this->postJson("/api/professional/invoices/{$invoice->id}/mark-as-paid", ['method' => 'cash']);
        $rejected->assertStatus(422);
        $this->assertSame('pending', $invoice->fresh()->status);

        $accepted = $this->postJson("/api/professional/invoices/{$invoice->id}/mark-as-paid", [
            'method' => 'cash',
            'credit_resolution' => 'Devolvido R$ 100 em dinheiro no balcão',
        ]);

        $accepted->assertOk()->assertJsonPath('data.status', 'paid');
        $settlement = Payment::where('invoice_id', $invoice->id)->where('purpose', 'settlement')->firstOrFail();
        $this->assertEqualsWithDelta(0.0, (float) $settlement->amount, 0.001);
        $this->assertSame('Devolvido R$ 100 em dinheiro no balcão', $settlement->metadata['credit_resolution'] ?? null);
    }

    /** §3-bis.2: a trava de duplicidade continua bloqueando um segundo acerto final. */
    public function test_a_second_mark_as_paid_on_an_already_settled_invoice_still_fails(): void
    {
        $invoice = $this->makeInvoice('hospitalization', 500);
        $this->postJson("/api/professional/invoices/{$invoice->id}/mark-as-paid", ['method' => 'cash'])
            ->assertOk();

        $response = $this->postJson("/api/professional/invoices/{$invoice->id}/mark-as-paid", ['method' => 'cash']);

        $response->assertStatus(422);
        $this->assertSame(1, Payment::where('invoice_id', $invoice->id)->where('purpose', 'settlement')->count());
    }

    private function makeHospitalizationAppointment(): Appointment
    {
        return Appointment::create([
            'professional_id' => $this->vet->id,
            'client_id' => $this->tutor->id,
            'pet_id' => $this->pet->id,
            'appointment_date' => now()->toDateString(),
            'appointment_time' => '09:00',
            'type' => 'hospitalization',
            'status' => 'in_progress',
        ]);
    }

    private function makeInvoiceForAppointment(Appointment $appointment, float $total): Invoice
    {
        return Invoice::create([
            'professional_id' => $this->vet->id,
            'client_id' => $this->tutor->id,
            'appointment_id' => $appointment->id,
            'invoice_number' => 'INV-'.uniqid(),
            'issue_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'items' => [['description' => 'Diária de internação', 'quantity' => 1, 'unit_price' => $total, 'total' => $total]],
            'subtotal' => $total,
            'total' => $total,
            'status' => 'pending',
        ]);
    }

    private function makeInvoice(string $type, float $total): Invoice
    {
        $appointment = $type === 'hospitalization'
            ? $this->makeHospitalizationAppointment()
            : Appointment::create([
                'professional_id' => $this->vet->id,
                'client_id' => $this->tutor->id,
                'pet_id' => $this->pet->id,
                'appointment_date' => now()->toDateString(),
                'appointment_time' => '09:00',
                'type' => $type,
                'status' => 'in_progress',
            ]);

        return $this->makeInvoiceForAppointment($appointment, $total);
    }
}
