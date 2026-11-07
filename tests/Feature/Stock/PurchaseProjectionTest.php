<?php

namespace Tests\Feature\Stock;

use App\Enums\PurchaseOrderStatus;
use App\Enums\StockMovementType;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Stock\StockService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Projeção de compra (docs/gap-petflow/PLANO.md, 3.4): janela, pedido aberto descontado, perdas
 * fora do consumo, geração de pedidos agrupada por fornecedor, 422 sem fornecedor, outra
 * organização e permissão. Hoje congelado em 07/11/2026 (meio da tarde em São Paulo).
 */
class PurchaseProjectionTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    private Supplier $main;

    private Supplier $other;

    private Product $food;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-07 18:00:00', 'UTC'));
        $this->buildClinic();
        $this->main = $this->supplier('Distribuidora Principal', 10);
        $this->other = $this->supplier('Atacado Secundário', 5);
        $this->food = $this->makeProduct(['name' => 'Ração Adulto 10kg', 'stock_quantity' => 0, 'last_cost' => 10, 'preferred_supplier_id' => $this->main->id]);

        $stock = app(StockService::class);
        $stock->in($this->food, StockMovementType::PURCHASE_IN, '100', ['total_cost' => '1000.00', 'occurred_at' => now()->subDays(60)]);
        // 30 vendidos dentro dos 7 dias; 30 de uso interno há 18 dias (só nas janelas de 30 e 90).
        $stock->out($this->food->fresh(), StockMovementType::SALE_OUT, '30', ['occurred_at' => now()->subDays(6)]);
        $stock->out($this->food->fresh(), StockMovementType::INTERNAL_USE, '30', ['occurred_at' => now()->subDays(18)]);
        // Perda de 5: sai do saldo (35), mas NÃO do consumo.
        $stock->out($this->food->fresh(), StockMovementType::LOSS, '5', ['occurred_at' => now()->subDays(4)]);
    }

    public function test_projection_per_window_deducts_open_orders_and_keeps_losses_apart(): void
    {
        $this->openOrders();

        // Janela 30: consumo 60/30 = 2/dia; 35 ÷ 2 = 17,5 dias → ruptura 07/11 + 17 = 24/11.
        // Sugestão = 2 × (10 + 30) − 35 − 26 (em pedido aberto) = 19.
        $row = $this->row(30);
        $this->assertSame(30, $row['window_days']);
        $this->assertSame(['2.0000', '17.5', '2026-11-24', '19', '26.0000'], [
            $row['daily_consumption'], $row['days_remaining'], $row['rupture_date'], $row['suggested_quantity'], $this->quantity($row['open_order_quantity']),
        ]);
        $this->assertSame(['5.0000', '50.00'], [$this->quantity($row['losses']['quantity']), $row['losses']['value']]);
        $this->assertSame([10, 30, '1', $this->main->id], [$row['lead_time_days'], $row['target_coverage_days'], $row['purchase_multiple'], $row['supplier']['id']]);

        // Janela 7: só a venda de 30 → 30/7 = 4,2857/dia; o uso interno de 18 dias atrás fica fora.
        $this->assertSame('4.2857', $this->row(7)['daily_consumption']);

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/professional/reports/stock-analysis?window=10')
            ->assertUnprocessable()->assertJsonValidationErrors('window');
    }

    public function test_generates_one_draft_order_per_supplier_from_the_suggestions(): void
    {
        $collar = $this->makeProduct(['name' => 'Coleira', 'stock_quantity' => 0, 'last_cost' => 7.5, 'last_supplier_id' => $this->other->id]);
        $sand = $this->makeProduct(['name' => 'Areia', 'stock_quantity' => 0, 'last_cost' => 3.333, 'preferred_supplier_id' => $this->main->id]);

        $response = $this->generate(['window' => 30, 'items' => [
            ['product_id' => $this->food->id],
            ['product_id' => $collar->id, 'quantity' => 6],
            ['product_id' => $sand->id, 'quantity' => 3],
        ]])->assertCreated()->assertJsonCount(2, 'data');

        $orders = collect($response->json('data'))->keyBy('supplier_id');
        $main = $orders[$this->main->id];
        $this->assertSame('draft', $main['status']);
        // Sem pedido aberto: 80 − 35 = 45 de ração (sugestão) + 3 de areia a 3,333 = 9,999 → 10,00.
        $this->assertEqualsCanonicalizing([[$this->food->id, '45.0000'], [$sand->id, '3.0000']], collect($main['items'])->map(fn (array $i): array => [$i['product_id'], $this->quantity($i['quantity'])])->all());
        $this->assertEquals(460.00, $main['total']);
        $this->assertEquals(45.00, $orders[$this->other->id]['total']);

        // A projeção passa a enxergar os 45 em pedido aberto: sugestão zera.
        $this->assertSame('0', $this->row(30)['suggested_quantity']);
    }

    public function test_product_without_supplier_is_refused_and_creates_nothing(): void
    {
        $orphan = $this->makeProduct(['name' => 'Sem fornecedor', 'stock_quantity' => 0, 'last_cost' => 2]);

        $this->generate(['items' => [['product_id' => $this->food->id], ['product_id' => $orphan->id, 'quantity' => 4]]])
            ->assertUnprocessable()->assertJsonValidationErrors('items.1.supplier_id');
        $this->assertSame(0, PurchaseOrder::count());

        // Sem consumo nem quantidade informada: não há o que pedir.
        $this->generate(['items' => [['product_id' => $orphan->id, 'supplier_id' => $this->other->id]]])
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');

        // Com o fornecedor informado no item, passa.
        $this->generate(['items' => [['product_id' => $orphan->id, 'quantity' => 4, 'supplier_id' => $this->other->id]]])
            ->assertCreated()->assertJsonPath('data.0.supplier_id', $this->other->id);
    }

    public function test_other_organization_and_permission(): void
    {
        $other = Organization::factory()->create();
        $stranger = User::factory()->professional()->create();
        OrganizationMember::factory()->owner()->for($other, 'organization')->create(['user_id' => $stranger->id]);
        $foreignSupplier = Supplier::create(['organization_id' => $other->id, 'professional_id' => $stranger->id, 'legal_name' => 'Deles']);

        $ids = collect($this->actingAs($stranger, 'sanctum')->getJson('/api/professional/reports/stock-analysis')->assertOk()->json('data'))->pluck('product.id');
        $this->assertNotContains($this->food->id, $ids);
        $this->generate(['items' => [['product_id' => $this->food->id, 'quantity' => 1]]], $stranger)
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.product_id');
        $this->generate(['items' => [['product_id' => $this->food->id, 'quantity' => 1, 'supplier_id' => $foreignSupplier->id]]])
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.supplier_id');
        $this->actingAs($this->owner, 'sanctum')->patchJson("/api/professional/products/{$this->food->id}", ['preferred_supplier_id' => $foreignSupplier->id])
            ->assertUnprocessable()->assertJsonValidationErrors('preferred_supplier_id');

        // Sem `products.update` (aqui, um tutor) não gera pedido.
        $this->generate(['items' => [['product_id' => $this->food->id]]], $this->tutor)->assertForbidden();
        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_product_parameters_are_saved_and_returned(): void
    {
        $this->actingAs($this->owner, 'sanctum')->patchJson("/api/professional/products/{$this->food->id}", [
            'preferred_supplier_id' => $this->other->id, 'lead_time_days' => 7, 'target_coverage_days' => 14, 'purchase_multiple' => 12, 'use_safety_stock' => true,
        ])->assertOk()->assertJsonPath('data.lead_time_days', 7)->assertJsonPath('data.preferred_supplier_id', $this->other->id);

        // 2 × (7 + 14) = 42 + estoque de segurança − 35, arredondado ao múltiplo de 12.
        $row = $this->row(30);
        $this->assertSame([7, 14, '12.0000', true, $this->other->id], [$row['lead_time_days'], $row['target_coverage_days'], $row['purchase_multiple'], $row['use_safety_stock'], $row['supplier']['id']]);
        $this->assertSame(0, ((int) $row['suggested_quantity']) % 12);
    }

    private function supplier(string $name, int $leadTime): Supplier
    {
        return Supplier::create(['organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id, 'legal_name' => $name, 'lead_time_days' => $leadTime]);
    }

    /** Rascunho de 20, parcial de 10 com 4 recebidos (6 abertos) e um cancelado de 7 (fora). */
    private function openOrders(): void
    {
        $as = $this->actingAs($this->owner, 'sanctum');
        $as->postJson('/api/professional/purchase-orders', ['supplier_id' => $this->main->id, 'items' => [['product_id' => $this->food->id, 'quantity' => 20]]])->assertCreated();
        $partial = $as->postJson('/api/professional/purchase-orders', ['supplier_id' => $this->main->id, 'items' => [['product_id' => $this->food->id, 'quantity' => 10]]])->json('data.id');
        PurchaseOrder::find($partial)->update(['status' => PurchaseOrderStatus::PARTIALLY_RECEIVED]);
        PurchaseOrder::find($partial)->items()->update(['received_quantity' => 4]);
        $cancelled = $as->postJson('/api/professional/purchase-orders', ['supplier_id' => $this->main->id, 'items' => [['product_id' => $this->food->id, 'quantity' => 7]]])->json('data.id');
        $as->postJson("/api/professional/purchase-orders/{$cancelled}/cancel")->assertOk();
    }

    /** @return array<string, mixed> */
    private function row(int $window): array
    {
        $data = $this->actingAs($this->owner, 'sanctum')->getJson("/api/professional/reports/stock-analysis?window={$window}")->assertOk()->json('data');

        return collect($data)->firstWhere('product.id', $this->food->id)['projection'];
    }

    /** @param  array<string, mixed>  $payload */
    private function generate(array $payload, ?User $as = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($as ?? $this->owner, 'sanctum')->postJson('/api/professional/stock/purchase-suggestions/orders', $payload);
    }

    private function quantity(string|int|float $value): string
    {
        return number_format((float) $value, 4, '.', '');
    }
}
