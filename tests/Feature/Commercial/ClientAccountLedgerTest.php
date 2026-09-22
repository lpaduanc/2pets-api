<?php

namespace Tests\Feature\Commercial;

use App\Models\CompanyClientAccount;
use App\Models\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Conta corrente do cliente — critérios de aceite do
 * docs/gap-simplesvet/specs/11-conta-corrente-do-cliente-spec.md: venda fiado gera débito,
 * quitação manual zera o saldo, e o extrato sempre reconstitui o saldo em cache.
 */
class ClientAccountLedgerTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildClinic();
    }

    public function test_a_credit_sale_debits_the_client_and_a_later_manual_payment_settles_it(): void
    {
        $this->enableCreditSale($this->tutor->id, creditLimit: 300);

        $this->openRegisterFor($this->receptionist, 0);
        $cash = $this->paymentMethodId($this->receptionist, 'cash');
        $product = $this->makeProduct(['price' => 200]);

        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale', 'client_id' => $this->tutor->id])
            ->assertCreated()->json('data.id');
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $product->id]);

        // Paga só R$ 50 agora: os R$ 150 restantes viram fiado (débito automático).
        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $cash, 'amount' => 50])
            ->assertCreated()
            ->assertJsonPath('sale.status', 'unpaid');

        $statement = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/professional/clients/{$this->tutor->id}/account-statement")
            ->assertOk();

        $this->assertEqualsWithDelta(-150.0, $statement->json('balance'), 0.01);
        $this->assertSame('sale_debit', collect($statement->json('data'))->last()['type']);

        // Recebimento posterior de R$ 150 em dinheiro: quitação manual, zera o saldo.
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/professional/clients/{$this->tutor->id}/account-entries", [
                'type' => 'payment_credit', 'amount' => 150,
            ])->assertCreated();

        $final = $this->getJson("/api/professional/clients/{$this->tutor->id}/account-statement")->assertOk();
        $this->assertEqualsWithDelta(0.0, $final->json('balance'), 0.01);
        $this->assertCount(2, $final->json('data'));
    }

    public function test_a_credit_sale_without_allow_credit_sale_is_rejected(): void
    {
        $this->openRegisterFor($this->receptionist, 0);
        $cash = $this->paymentMethodId($this->receptionist, 'cash');
        $product = $this->makeProduct(['price' => 200]);

        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale', 'client_id' => $this->tutor->id])
            ->assertCreated()->json('data.id');
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $product->id]);

        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $cash, 'amount' => 50])
            ->assertStatus(422);
    }

    public function test_a_credit_sale_above_the_limit_is_rejected_with_a_readable_message(): void
    {
        $this->enableCreditSale($this->tutor->id, creditLimit: 100);

        $this->openRegisterFor($this->receptionist, 0);
        $cash = $this->paymentMethodId($this->receptionist, 'cash');
        $product = $this->makeProduct(['price' => 200]);

        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale', 'client_id' => $this->tutor->id])
            ->assertCreated()->json('data.id');
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $product->id]);

        // Falta R$ 150 (limite é só R$ 100).
        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $cash, 'amount' => 50])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => $this->creditLimitMessage(100, 0)]);
    }

    public function test_an_advance_deposit_lets_the_client_pay_with_account_credit(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/professional/clients/{$this->tutor->id}/account-entries", [
                'type' => 'advance_credit', 'amount' => 500,
            ])->assertCreated();

        $this->openRegisterFor($this->receptionist, 0);
        $accountCredit = $this->accountCreditMethod();
        $product = $this->makeProduct(['price' => 300]);

        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale', 'client_id' => $this->tutor->id])
            ->assertCreated()->json('data.id');
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $product->id]);

        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $accountCredit, 'amount' => 300])
            ->assertCreated()
            ->assertJsonPath('sale.status', 'paid');

        $balance = $this->getJson("/api/professional/clients/{$this->tutor->id}/account-statement")->json('balance');
        $this->assertEqualsWithDelta(200.0, $balance, 0.01);
    }

    public function test_account_credit_beyond_the_available_balance_is_rejected(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/professional/clients/{$this->tutor->id}/account-entries", [
                'type' => 'advance_credit', 'amount' => 100,
            ])->assertCreated();

        $this->openRegisterFor($this->receptionist, 0);
        $accountCredit = $this->accountCreditMethod();
        $product = $this->makeProduct(['price' => 300]);

        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale', 'client_id' => $this->tutor->id])
            ->assertCreated()->json('data.id');
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $product->id]);

        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $accountCredit, 'amount' => 300])
            ->assertStatus(422);
    }

    public function test_cancelling_a_sale_paid_with_account_credit_refunds_the_client(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/professional/clients/{$this->tutor->id}/account-entries", [
                'type' => 'advance_credit', 'amount' => 500,
            ])->assertCreated();

        $registerId = $this->openRegisterFor($this->receptionist, 0);
        $accountCredit = $this->accountCreditMethod();
        $product = $this->makeProduct(['price' => 300]);

        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale', 'client_id' => $this->tutor->id])
            ->assertCreated()->json('data.id');
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $product->id]);
        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $accountCredit, 'amount' => 300])->assertCreated();
        $this->postJson("/api/professional/cash-registers/{$registerId}/close", ['counted' => ['none' => 0]])->assertOk();

        // Cancelamento sem NENHUM caixa aberto (o da recepcionista já fechou acima) — a
        // devolução de crédito não pode depender de gaveta, é saldo do cliente, não dinheiro.
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/professional/sales/{$saleId}/cancel", ['reason' => 'Cliente desistiu'])
            ->assertOk();

        $balance = $this->getJson("/api/professional/clients/{$this->tutor->id}/account-statement")->json('balance');
        $this->assertEqualsWithDelta(500.0, $balance, 0.01);
    }

    public function test_the_ledger_always_reconciles_with_the_cached_balance(): void
    {
        $this->enableCreditSale($this->tutor->id, creditLimit: 1000);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/professional/clients/{$this->tutor->id}/account-entries", ['type' => 'advance_credit', 'amount' => 500])
            ->assertCreated();
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/professional/clients/{$this->tutor->id}/account-entries", ['type' => 'adjustment_debit', 'amount' => 120])
            ->assertCreated();

        $account = CompanyClientAccount::where('client_id', $this->tutor->id)->sole();
        $reconciled = $account->entries()->get()->sum(fn ($e) => $e->signedAmount());

        $this->assertEqualsWithDelta((float) $account->current_balance, round($reconciled, 2), 0.01);
        $this->assertEqualsWithDelta(380.0, (float) $account->current_balance, 0.01);
    }

    /** `account_credit` não está entre as formas padrão (`PaymentMethodProvisioner`) — cadastra direto. */
    private function accountCreditMethod(): int
    {
        return PaymentMethod::create([
            'organization_id' => $this->clinic->id,
            'professional_id' => $this->owner->id,
            'name' => 'Conta corrente do cliente',
            'kind' => 'account_credit',
            'direction' => 'in',
            'active' => true,
        ])->id;
    }

    private function enableCreditSale(int $clientId, float $creditLimit): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/professional/clients/{$clientId}/account-settings", [
                'credit_limit' => $creditLimit, 'allow_credit_sale' => true,
            ])->assertOk();
    }

    private function creditLimitMessage(float $limit, float $balance): string
    {
        return sprintf(
            'Venda a prazo excede o limite de crédito do cliente (limite R$ %s, saldo atual R$ %s).',
            number_format($limit, 2, ',', '.'),
            number_format($balance, 2, ',', '.'),
        );
    }
}
