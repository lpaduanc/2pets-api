<?php

namespace Tests\Feature\Commercial\Profitability;

use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SaleItemProfitability;
use App\Services\Commercial\Profitability\SaleProfitabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * `GET sales/{id}/profitability` e o recálculo por evento (docs/gap-petflow/PLANO.md, 1.5), de
 * ponta a ponta pelo PDV. Golden `rentab-01` montado de verdade: ração 200,00 (custo médio 140)
 * + banho 60,00 (custo direto 6,50, comissão do cadastro 28,5% sobre o bruto = 17,10, com
 * responsável), desconto do cabeçalho 13,00, crédito 1x 2,99% (taxa 7,39), alíquota 6%.
 */
class SaleProfitabilityTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    private int $saleId;

    private PaymentMethod $credit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildClinic();
        $this->clinic->update(['effective_sales_tax_rate' => '6']);
        $this->credit = PaymentMethod::findOrFail($this->paymentMethodId($this->owner, 'credit_card'));
        $this->credit->update(['max_installments' => 12]);
        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/professional/payment-methods/{$this->credit->id}/fee-tiers", ['tiers' => [[
                'card_brand' => null, 'installments_from' => 1, 'installments_to' => 12, 'fee_percent' => '2.99',
                'fee_fixed' => '0.00', 'first_settlement_days' => 30, 'interval_days' => 30,
            ]]])
            ->assertOk();
        $this->openRegisterFor($this->receptionist);
        $this->saleId = $this->actingAs($this->receptionist, 'sanctum')
            ->postJson('/api/professional/sales', ['kind' => 'sale', 'client_id' => $this->tutor->id])
            ->assertCreated()
            ->json('data.id');
    }

    public function test_rentab_01_through_the_counter_matches_the_golden(): void
    {
        $this->rentab01();

        $response = $this->profitability()->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.totals', [
                'net_revenue' => '247.00', 'cost_amount' => '146.50', 'card_fee_share' => '7.39', 'tax_amount' => '14.82',
                'commission_amount' => '17.10', 'contribution_margin' => '61.19', 'margin_percent' => '24.77',
            ]);

        $items = collect($response->json('data.items'))->keyBy('description');
        $this->assertSame(
            ['200.00', '10.00', '190.00', '140.00', '5.68', '11.40', '0.00', '32.92', '17.33'],
            array_values(array_slice($items['Ração Premium 3kg'], 4)),
        );
        $this->assertSame(
            ['60.00', '3.00', '57.00', '6.50', '1.71', '3.42', '17.10', '28.27', '49.60'],
            array_values(array_slice($items['Banho porte médio'], 4)),
        );
    }

    public function test_open_sale_is_pending(): void
    {
        $this->addItem('product', $this->makeProduct(['controls_stock' => false])->id);

        $this->profitability()->assertOk()->assertExactJson(['data' => [
            'status' => 'pending', 'computed_at' => null, 'version' => null, 'items' => [], 'totals' => null,
        ]]);
    }

    public function test_permission_is_judged_by_the_role_in_the_sale_organization(): void
    {
        $this->rentab01();

        // A recepcionista também é vet volante (`vet_freelancer` tem a permissão), mas aqui é atendente.
        $this->profitability($this->receptionist)->assertForbidden();
        $this->receptionistMember->update(['permissions' => ['reports.profitability']]);
        $this->profitability($this->receptionist)->assertOk();
    }

    public function test_sale_of_another_clinic_is_not_found(): void
    {
        $other = Organization::factory()->petshop()->create();
        $foreign = Sale::create(['organization_id' => $other->id, 'professional_id' => $this->owner->id, 'number' => 999, 'kind' => 'sale', 'status' => 'paid', 'fiscal_operation' => 'in_person_consumer', 'discount_type' => 'none', 'created_by' => $this->owner->id]);

        $this->actingAs($this->owner, 'sanctum')->getJson("/api/professional/sales/{$foreign->id}/profitability")->assertNotFound();
    }

    public function test_recompute_is_idempotent_and_a_partial_return_bumps_the_version(): void
    {
        $productItem = $this->rentab01();
        app(SaleProfitabilityService::class)->recompute(Sale::findOrFail($this->saleId));
        $this->assertSame(1, SaleItemProfitability::where('sale_item_id', $productItem)->value('version'));

        // Devolve a ração inteira: os valores dela zeram (versão 2); o banho não muda (versão 1).
        $this->actingAs($this->receptionist, 'sanctum')->postJson('/api/professional/sale-returns', [
            'sale_id' => $this->saleId, 'reason' => 'Embalagem violada', 'refund_method' => 'none',
            'items' => [['sale_item_id' => $productItem, 'quantity' => 1]],
        ])->assertCreated();

        $row = SaleItemProfitability::where('sale_item_id', $productItem)->sole();
        $this->assertSame([2, '0.0000', '0.00', '0.00'], [$row->version, $row->quantity, $row->net_revenue, $row->contribution_margin]);
        $this->assertSame(1, SaleItemProfitability::where('sale_item_id', '!=', $productItem)->value('version'));
    }

    public function test_courtesy_only_sale_finalized_without_payment_has_negative_margin(): void
    {
        $itemId = $this->addItem('product', $this->makeProduct(['name' => 'Ração Teste A', 'average_cost' => '21.0000'])->id);
        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/professional/sales/{$this->saleId}/items/{$itemId}/courtesy", ['kind' => 'gift', 'reason' => 'Brinde'])
            ->assertOk();
        $this->postJson("/api/professional/sales/{$this->saleId}/finalize")->assertOk();

        $this->profitability()->assertOk()
            ->assertJsonPath('data.items.0.courtesy_kind', 'gift')
            ->assertJsonPath('data.items.0.cost_amount', '21.00')
            ->assertJsonPath('data.items.0.contribution_margin', '-21.00')
            ->assertJsonPath('data.items.0.margin_percent', null)
            ->assertJsonPath('data.totals.net_revenue', '0.00')
            ->assertJsonPath('data.totals.margin_percent', null);
    }

    public function test_cancelling_the_sale_removes_the_result(): void
    {
        $this->rentab01();
        $this->assertSame(2, SaleItemProfitability::where('sale_id', $this->saleId)->count());

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/professional/sales/{$this->saleId}/cancel", ['reason' => 'Erro'])->assertOk();

        $this->assertSame(0, SaleItemProfitability::where('sale_id', $this->saleId)->count());
        $this->profitability()->assertJsonPath('data.status', 'pending');
    }

    public function test_backfill_command_computes_paid_sales_without_rows(): void
    {
        $this->rentab01();
        SaleItemProfitability::query()->delete();

        $this->artisan('profitability:backfill', ['--from' => now()->subDay()->toDateString()])
            ->expectsOutputToContain('1 venda(s)')
            ->assertSuccessful();

        $this->assertSame(['61.19'], [(string) SaleItemProfitability::where('sale_id', $this->saleId)->get()->sum(fn ($row) => (float) $row->contribution_margin)]);
    }

    /** Monta e paga a venda do `rentab-01`; devolve o id do item da ração. */
    private function rentab01(): int
    {
        $productItem = $this->addItem('product', $this->makeProduct(['price' => 200, 'average_cost' => '140.0000', 'controls_stock' => false])->id);
        $service = $this->makeService(['price' => 60, 'direct_cost' => '6.50', 'commission_percent' => '28.5']);
        $this->addItem('service', $service->id, ['staff_id' => $this->groomerMember->id]);
        $this->actingAs($this->receptionist, 'sanctum')
            ->postJson("/api/professional/sales/{$this->saleId}/discount", ['discount_type' => 'amount', 'discount_value' => 13])
            ->assertOk();
        $this->postJson("/api/professional/sales/{$this->saleId}/receipts", ['payment_method_id' => $this->credit->id, 'amount' => '247.00', 'installments' => 1])
            ->assertCreated()
            ->assertJsonPath('sale.status', 'paid');
        $this->assertSame('7.39', (string) Sale::findOrFail($this->saleId)->receipts()->value('operator_fee'));

        return $productItem;
    }

    /** @param  array<string, mixed>  $body */
    private function addItem(string $type, int $id, array $body = []): int
    {
        return $this->actingAs($this->receptionist, 'sanctum')
            ->postJson("/api/professional/sales/{$this->saleId}/items", ['sellable_type' => $type, 'sellable_id' => $id] + $body)
            ->assertCreated()
            ->json('data.id');
    }

    private function profitability(?\App\Models\User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->owner, 'sanctum')->getJson("/api/professional/sales/{$this->saleId}/profitability");
    }
}
