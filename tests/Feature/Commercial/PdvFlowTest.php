<?php

namespace Tests\Feature\Commercial;

use App\Models\Appointment;
use App\Models\CashRegisterMovement;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Pet;
use App\Models\SaleItem;
use App\Models\SaleReceipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Jornada completa do balcão (docs/gap-simplesvet/01-caixa-pdv.md) e a costura com os fluxos
 * que já existiam: a conta do atendimento (`mark-as-paid`) e o adiantamento da internação
 * (`advance-payment`) também entram no caixa aberto de quem recebeu.
 */
class PdvFlowTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildClinic();
    }

    public function test_full_counter_day_from_opening_to_settlement(): void
    {
        $registerId = $this->openRegisterFor($this->receptionist, 100);
        $cash = $this->paymentMethodId($this->receptionist, 'cash');
        $credit = $this->paymentMethodId($this->receptionist, 'credit_card');
        $product = $this->makeProduct(['price' => 100, 'commission_percent' => 5, 'stock_quantity' => 10]);
        $service = $this->makeService(['price' => 60, 'commission_percent' => 30]);

        $saleId = $this->postJson('/api/professional/sales', [
            'kind' => 'sale',
            'client_id' => $this->tutor->id,
            'pet_id' => $this->pet->id,
            'printed_notes' => 'Retorno em 30 dias',
        ])->assertCreated()->json('data.id');

        // 1 produto (balconista) + 1 serviço (groomer): cada item com o seu responsável.
        $this->postJson("/api/professional/sales/{$saleId}/items", [
            'sellable_type' => 'product', 'sellable_id' => $product->id, 'staff_id' => $this->receptionistMember->id,
        ])->assertCreated();
        $this->postJson("/api/professional/sales/{$saleId}/items", [
            'sellable_type' => 'service', 'sellable_id' => $service->id, 'staff_id' => $this->groomerMember->id,
        ])->assertCreated()->assertJsonPath('sale.total', 160);

        // Contrato com o doc 09: duas linhas, dois responsáveis, percentual congelado em cada
        // uma — é daqui que nascem os dois registros de comissão.
        $items = SaleItem::where('sale_id', $saleId)->orderBy('id')->get();
        $this->assertSame([$this->receptionistMember->id, $this->groomerMember->id], $items->pluck('staff_id')->all());
        $this->assertEquals([5.0, 30.0], $items->map(fn (SaleItem $item): float => (float) $item->commission_percent)->all());

        // Dinheiro + cartão na mesma venda: 2 recebimentos, 2 movimentos de caixa.
        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $cash, 'amount' => 60])
            ->assertCreated()
            ->assertJsonPath('sale.status', 'unpaid')
            ->assertJsonPath('sale.amount_due', 100);
        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $credit, 'amount' => 100, 'installments' => 2])
            ->assertCreated()
            ->assertJsonPath('sale.status', 'paid')
            ->assertJsonPath('sale.fiscal_pending', ['nfce', 'nfse']);

        $this->assertSame(2, SaleReceipt::where('sale_id', $saleId)->count());
        $this->assertSame(2, CashRegisterMovement::where('cash_register_id', $registerId)->where('type', 'sale_receipt')->count());
        $this->assertSame(9, $product->fresh()->stock_quantity);

        // Fechamento: esperado × contado por forma, diferença gravada.
        $closed = $this->postJson("/api/professional/cash-registers/{$registerId}/close", [
            'counted' => [(string) $cash => 158, (string) $credit => 100],
        ])->assertOk();

        $rows = collect($closed->json('data.closing_breakdown'))->keyBy('payment_method_id');
        $this->assertEquals(160, $rows[$cash]['expected']);
        $this->assertEquals(-2, $rows[$cash]['difference']);
        $this->assertEquals(0, $rows[$credit]['difference']);
        $closed->assertJsonPath('data.difference', -2);

        // Caixa fechado não aceita mais venda.
        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $cash, 'amount' => 1])
            ->assertStatus(422);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/professional/cash-registers/{$registerId}/settle")
            ->assertOk()
            ->assertJsonPath('data.status', 'settled');

        $this->getJson("/api/professional/cash-registers/{$registerId}")
            ->assertOk()
            ->assertJsonPath('data.totals.expected_cash', 160)
            ->assertJsonPath('data.abilities.settle', false);
    }

    public function test_receiving_without_an_open_register_is_refused(): void
    {
        $registerId = $this->openRegisterFor($this->receptionist, 0);
        $cash = $this->paymentMethodId($this->receptionist, 'cash');
        $product = $this->makeProduct(['price' => 50]);
        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale'])->json('data.id');
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $product->id]);

        $this->postJson("/api/professional/cash-registers/{$registerId}/close", ['counted' => ['none' => 0]])->assertOk();

        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $cash, 'amount' => 50])
            ->assertStatus(422)
            ->assertJsonPath('code', 'cash_register_required');
    }

    public function test_a_sale_left_open_yesterday_is_received_in_todays_register(): void
    {
        $yesterday = $this->openRegisterFor($this->receptionist, 0);
        $cash = $this->paymentMethodId($this->receptionist, 'cash');
        $product = $this->makeProduct(['price' => 50]);
        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale'])->json('data.id');
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $product->id]);
        $this->postJson("/api/professional/cash-registers/{$yesterday}/close", ['counted' => ['none' => 0]])->assertOk();

        $today = $this->openRegisterFor($this->receptionist, 0);
        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $cash, 'amount' => 50])
            ->assertCreated();

        $this->assertSame(1, CashRegisterMovement::where('cash_register_id', $today)->where('type', 'sale_receipt')->count());
        $this->assertSame(0, CashRegisterMovement::where('cash_register_id', $yesterday)->where('type', 'sale_receipt')->count());
    }

    public function test_marking_an_appointment_invoice_as_paid_enters_the_open_register(): void
    {
        [$vet, $invoice] = $this->freelancerInvoice('consultation', 180);
        $registerId = $this->openRegisterFor($vet, 50);

        $this->actingAs($vet, 'sanctum')
            ->postJson("/api/professional/invoices/{$invoice->id}/mark-as-paid", ['method' => 'cash'])
            ->assertOk();

        $movement = CashRegisterMovement::where('cash_register_id', $registerId)->where('type', 'sale_receipt')->sole();
        $payment = Payment::where('invoice_id', $invoice->id)->sole();

        $this->assertEqualsWithDelta(180.0, (float) $movement->amount, 0.001);
        $this->assertSame(Payment::class, $movement->reference_type);
        $this->assertSame($payment->id, $movement->reference_id);
        $this->assertSame('cash', $movement->paymentMethod->kind->value);
        $this->assertStringContainsString($invoice->invoice_number, $movement->description);

        // A gaveta passa a esperar troco + consulta.
        $this->getJson("/api/professional/cash-registers/{$registerId}")
            ->assertOk()
            ->assertJsonPath('data.totals.expected_cash', 230);
    }

    public function test_hospitalization_advance_payment_enters_the_open_register(): void
    {
        [$vet, $invoice] = $this->freelancerInvoice('hospitalization', 1000);
        $registerId = $this->openRegisterFor($vet, 0);

        $this->actingAs($vet, 'sanctum')
            ->postJson("/api/professional/invoices/{$invoice->id}/advance-payment", ['method' => 'pix', 'amount' => 300])
            ->assertOk();

        $movement = CashRegisterMovement::where('cash_register_id', $registerId)->sole();
        $this->assertEqualsWithDelta(300.0, (float) $movement->amount, 0.001);
        $this->assertSame('pix', $movement->paymentMethod->kind->value);
        $this->assertStringStartsWith('Adiantamento da conta', $movement->description);
    }

    public function test_invoice_payment_without_an_open_register_keeps_working_as_before(): void
    {
        [$vet, $invoice] = $this->freelancerInvoice('consultation', 180);

        $this->actingAs($vet, 'sanctum')
            ->postJson("/api/professional/invoices/{$invoice->id}/mark-as-paid", ['method' => 'pix'])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');

        $this->assertSame(0, CashRegisterMovement::count());
    }

    /**
     * Vet volante (sem organização) com uma fatura pendente de atendimento — o mesmo cenário
     * de `AdvancePaymentTest`.
     *
     * @return array{0: User, 1: Invoice}
     */
    private function freelancerInvoice(string $type, float $total): array
    {
        $vet = User::factory()->professional()->create();
        $tutor = User::factory()->tutor()->create();
        $pet = Pet::factory()->create(['user_id' => $tutor->id]);

        $appointment = Appointment::create([
            'professional_id' => $vet->id,
            'client_id' => $tutor->id,
            'pet_id' => $pet->id,
            'appointment_date' => now()->toDateString(),
            'appointment_time' => '09:00',
            'type' => $type,
            'status' => 'in_progress',
        ]);

        $invoice = Invoice::create([
            'professional_id' => $vet->id,
            'client_id' => $tutor->id,
            'appointment_id' => $appointment->id,
            'invoice_number' => 'INV-'.uniqid(),
            'issue_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'items' => [['description' => 'Atendimento', 'quantity' => 1, 'unit_price' => $total, 'total' => $total]],
            'subtotal' => $total,
            'total' => $total,
            'status' => 'pending',
        ]);

        return [$vet, $invoice];
    }
}
