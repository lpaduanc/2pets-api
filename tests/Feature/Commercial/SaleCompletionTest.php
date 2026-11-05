<?php

namespace Tests\Feature\Commercial;

use App\Models\CompanyClientAccount;
use App\Models\Sale;
use App\Services\Commercial\ClientAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * "Concluir venda" do PDV (`POST sales/{id}/complete`): o fiado é julgado ao concluir, não a cada
 * recebimento. Cliente sem fiado liberado paga dinheiro + cartão sem esbarrar na trava do doc 11;
 * concluir com saldo exige o fiado liberado e lança o débito.
 */
class SaleCompletionTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    private int $saleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildClinic();
        $this->openRegisterFor($this->receptionist);
        $this->saleId = $this->actingAs($this->receptionist, 'sanctum')
            ->postJson('/api/professional/sales', ['kind' => 'sale', 'client_id' => $this->tutor->id])->assertCreated()->json('data.id');
        $this->postJson("/api/professional/sales/{$this->saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $this->makeProduct(['price' => 100])->id])
            ->assertCreated();
    }

    public function test_client_without_fiado_pays_cash_and_card_and_the_sale_is_paid(): void
    {
        $this->receive('cash', '60.00')->assertJsonPath('sale.status', 'open')->assertJsonPath('sale.amount_due', 40);
        $this->receive('credit_card', '40.00')->assertJsonPath('sale.status', 'paid');

        $this->complete()->assertOk()->assertJsonPath('data.status', 'paid');
        $this->assertSame('0.00', app(ClientAccountService::class)->saleDebitBalance($this->saleId)->toString());
    }

    public function test_client_without_fiado_cannot_complete_with_a_balance(): void
    {
        $this->receive('cash', '60.00');

        $this->complete()->assertStatus(422)->assertJsonPath('message', 'Este cliente não está habilitado para venda a prazo (fiado).');
        $this->assertSame('open', Sale::findOrFail($this->saleId)->status->value);
    }

    public function test_client_with_fiado_completes_on_credit(): void
    {
        CompanyClientAccount::create(['organization_id' => $this->clinic->id, 'client_id' => $this->tutor->id, 'allow_credit_sale' => true, 'credit_limit' => '500.00']);
        $this->receive('cash', '60.00');

        $this->complete()->assertOk()->assertJsonPath('data.status', 'unpaid')->assertJsonPath('data.amount_due', 40);
        $this->assertSame('40.00', app(ClientAccountService::class)->saleDebitBalance($this->saleId)->toString());

        // Concluir de novo não lança outro débito.
        $this->complete()->assertOk();
        $this->assertSame('40.00', app(ClientAccountService::class)->saleDebitBalance($this->saleId)->toString());
    }

    public function test_anonymous_sale_with_a_balance_needs_a_client_to_complete(): void
    {
        $this->actingAs($this->receptionist, 'sanctum')->patchJson("/api/professional/sales/{$this->saleId}", ['client_id' => null])->assertOk();
        $this->receive('cash', '60.00');

        $this->complete()->assertStatus(422)->assertJsonValidationErrors('client_id');
    }

    private function receive(string $kind, string $amount): TestResponse
    {
        return $this->actingAs($this->receptionist, 'sanctum')->postJson("/api/professional/sales/{$this->saleId}/receipts", [
            'payment_method_id' => $this->paymentMethodId($this->receptionist, $kind), 'amount' => $amount, 'installments' => 1,
        ])->assertCreated();
    }

    private function complete(): TestResponse
    {
        return $this->actingAs($this->receptionist, 'sanctum')->postJson("/api/professional/sales/{$this->saleId}/complete");
    }
}
