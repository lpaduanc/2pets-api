<?php

namespace Tests\Feature\Commercial;

use App\Enums\ClientAccountEntryType;
use App\Models\ClientAccountEntry;
use App\Models\Sale;
use App\Services\Commercial\ClientAccountService;
use App\Services\Commercial\Reversal\SaleCancellationReversal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Cancelar venda a prazo (fiado) estorna o débito na conta corrente do cliente: o saldo volta
 * ao que era antes da venda, e o que ele já tinha quitado da dívida vira crédito a favor dele.
 */
class SaleCancellationCreditSaleTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    /** @var array{organization_id: int, professional_id: int} */
    private array $ownership;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildClinic();
        $this->actingAs($this->owner, 'sanctum');
        $this->openRegisterFor($this->owner);
        $this->ownership = ['organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id];
        app(ClientAccountService::class)->updateSettings($this->tutor, $this->ownership, 500, true);
    }

    public function test_cancelling_a_partly_paid_credit_sale_reverses_the_debit_and_keeps_what_was_settled_as_credit(): void
    {
        $saleId = $this->creditSale();
        $accounts = app(ClientAccountService::class);
        $this->assertEqualsWithDelta(-60.0, $accounts->balanceFor($this->tutor, $this->ownership), 0.001);

        // Cliente quita R$ 20,00 do fiado no balcão (lançamento manual, sem vínculo com a venda).
        $accounts->recordManualEntry($this->tutor, $this->ownership, ClientAccountEntryType::PAYMENT_CREDIT, 20, $this->owner, 'Quitação parcial');

        $this->postJson("/api/professional/sales/{$saleId}/cancel", ['reason' => 'Venda desfeita'])->assertOk();

        $reversal = ClientAccountEntry::where('type', 'sale_debit_reversal')->sole();
        $this->assertSame(['credit', '60.00', 'sale', $saleId], [$reversal->direction->value, (string) $reversal->amount, $reversal->reference_type, $reversal->reference_id]);
        // Antes da venda: 0,00. Débito 60 estornado, quitação de 20 vira crédito do cliente.
        $this->assertEqualsWithDelta(20.0, $accounts->balanceFor($this->tutor, $this->ownership), 0.001);

        app(SaleCancellationReversal::class)->reverse(Sale::findOrFail($saleId), $this->owner, null);
        $this->assertSame(1, ClientAccountEntry::where('type', 'sale_debit_reversal')->count());
    }

    public function test_cancelling_without_settlement_brings_the_balance_back_to_zero(): void
    {
        $saleId = $this->creditSale();

        $this->postJson("/api/professional/sales/{$saleId}/cancel", ['reason' => 'Venda desfeita'])->assertOk();

        $this->assertEqualsWithDelta(0.0, app(ClientAccountService::class)->balanceFor($this->tutor, $this->ownership), 0.001);
    }

    /** R$ 100,00, R$ 40,00 em dinheiro e R$ 60,00 no fiado. */
    private function creditSale(): int
    {
        $cash = $this->paymentMethodId($this->owner, 'cash');
        $product = $this->makeProduct(['price' => 100]);
        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale', 'client_id' => $this->tutor->id])->json('data.id');
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $product->id])->assertCreated();
        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $cash, 'amount' => 40])
            ->assertCreated()->assertJsonPath('sale.status', 'unpaid');

        return $saleId;
    }
}
