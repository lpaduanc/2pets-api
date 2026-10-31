<?php

namespace Tests\Feature\Commercial\Pricing;

use App\Models\Appointment;
use App\Models\AppointmentService;
use App\Models\ClientAccountEntry;
use App\Models\FiscalDocument;
use App\Models\MedicalRecord;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\Commercial\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Brinde/cortesia/retorno na linha da venda (docs/gap-petflow/PLANO.md, 1.4): só quem tem
 * `sales.grant_courtesy` no cargo da organização da venda marca; a linha vira líquido zero, sai do
 * rateio do desconto do cabeçalho, das regras de teto e de custo, do documento fiscal e devolve
 * sem reembolso. Venda de saldo zero fecha por `POST sales/{id}/finalize`, sem caixa.
 *
 * Rateio: A 100 + B 100 com desconto R$ 10,00 → 5,00 para cada. A vira cortesia → B leva os
 * 10,00 sozinha (90,00). Desmarcar A volta o preço 100,00 e o rateio 5,00 / 5,00.
 */
class SaleItemCourtesyTest extends TestCase
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
            ->postJson('/api/professional/sales', ['kind' => 'sale', 'client_id' => $this->tutor->id])
            ->assertCreated()
            ->json('data.id');
    }

    public function test_owner_marks_a_below_cost_line_and_the_sale_can_be_received(): void
    {
        $costlyId = $this->addItem($this->product(['average_cost' => '80.0000', 'allow_price_override' => true]), ['unit_price' => '70.00']);
        $this->addItem($this->product());
        $this->receive('170.00')->assertStatus(422)->assertJsonValidationErrors("items.{$costlyId}.unit_price");

        $this->mark($costlyId, $this->owner, 'gift')
            ->assertOk()
            ->assertJsonPath('data.courtesy_kind', 'gift')
            ->assertJsonPath('data.courtesy_reason', 'Cliente fiel')
            ->assertJsonPath('data.courtesy_original_unit_price', '70.00')
            ->assertJsonPath('data.courtesy_marked_by', ['id' => $this->owner->id, 'name' => $this->owner->name])
            ->assertJsonPath('data.unit_price', '0.00')
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.below_cost', false)
            ->assertJsonPath('sale.total', 100);

        $item = SaleItem::findOrFail($costlyId);
        $this->assertSame(['1.0000', '80.0000'], [$item->quantity, $item->unit_cost]);
        $this->assertSame(1, DB::table('activity_log')->where('event', 'sale.item_courtesy_marked')->where('causer_id', $this->owner->id)->count());
        $this->receive('100.00')->assertCreated()->assertJsonPath('sale.status', 'paid');
    }

    public function test_marking_requires_the_permission_of_the_role_in_this_organization(): void
    {
        $itemId = $this->addItem($this->product());

        // A recepcionista também é vet volante (`vet_freelancer` tem a permissão), mas aqui é atendente.
        $this->mark($itemId, $this->receptionist)->assertForbidden();
        // Dona de OUTRA clínica: o cargo de lá não vale aqui.
        $otherClinic = Organization::factory()->petshop()->create();
        OrganizationMember::factory()->owner()->for($otherClinic, 'organization')->create(['user_id' => $this->groomer->id]);
        $this->mark($itemId, $this->groomer)->assertForbidden();
        $this->assertNull(SaleItem::findOrFail($itemId)->courtesy_kind);

        // Exceção do dono para o membro.
        $this->receptionistMember->update(['permissions' => ['sales.grant_courtesy']]);
        $this->mark($itemId, $this->receptionist, 'follow_up')->assertOk()->assertJsonPath('data.courtesy_kind', 'follow_up');
    }

    public function test_kind_and_reason_are_required(): void
    {
        $itemId = $this->addItem($this->product());

        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/professional/sales/{$this->saleId}/items/{$itemId}/courtesy", ['kind' => 'bonus'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['kind', 'reason']);
    }

    public function test_courtesy_leaves_the_header_discount_allocation_and_unmarking_restores_it(): void
    {
        $aId = $this->addItem($this->product());
        $bId = $this->addItem($this->product());
        $this->actingAs($this->receptionist, 'sanctum')
            ->postJson("/api/professional/sales/{$this->saleId}/discount", ['discount_type' => 'amount', 'discount_value' => 10])
            ->assertOk();

        $this->mark($aId, $this->owner)->assertOk()->assertJsonPath('sale.total', 90);
        $this->assertSame([$aId => '0.00', $bId => '90.00'], $this->netTotals());

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/professional/sales/{$this->saleId}/items/{$aId}/courtesy")
            ->assertOk()
            ->assertJsonPath('data.courtesy_kind', null)
            ->assertJsonPath('data.unit_price', '100.00')
            ->assertJsonPath('sale.total', 190);
        $this->assertSame([$aId => '95.00', $bId => '95.00'], $this->netTotals());
        $this->assertSame(1, DB::table('activity_log')->where('event', 'sale.item_courtesy_unmarked')->count());
    }

    public function test_unmarking_needs_the_permission_and_a_marked_line(): void
    {
        $itemId = $this->addItem($this->product());

        $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/professional/sales/{$this->saleId}/items/{$itemId}/courtesy")
            ->assertStatus(422);
        $this->mark($itemId, $this->owner)->assertOk();
        $this->actingAs($this->receptionist, 'sanctum')->deleteJson("/api/professional/sales/{$this->saleId}/items/{$itemId}/courtesy")
            ->assertForbidden();
    }

    public function test_courtesy_line_refuses_price_and_discount_changes_but_accepts_quantity(): void
    {
        $itemId = $this->addItem($this->product(['allow_price_override' => true]));
        $this->mark($itemId, $this->owner)->assertOk();
        $url = "/api/professional/sales/{$this->saleId}/items/{$itemId}";

        $this->actingAs($this->receptionist, 'sanctum')->patchJson($url, ['unit_price' => '50.00'])->assertStatus(422)->assertJsonValidationErrors('unit_price');
        $this->patchJson($url, ['discount' => '5.00'])->assertStatus(422)->assertJsonValidationErrors('discount');
        $this->patchJson($url, ['quantity' => 2])->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_finalize_closes_a_zero_balance_sale_without_cash_and_deducts_stock(): void
    {
        $product = $this->product(['average_cost' => '30.0000']);
        $itemId = $this->addItem($product);
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/professional/sales/{$this->saleId}/finalize")
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');

        $this->mark($itemId, $this->owner)->assertOk();
        $movements = DB::table('cash_register_movements')->count();
        // Quem finaliza não precisa de caixa aberto.
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/professional/sales/{$this->saleId}/finalize")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');

        $sale = Sale::findOrFail($this->saleId);
        $this->assertNotNull($sale->sold_at);
        $this->assertSame(0, $sale->receipts()->count());
        $this->assertSame('9.0000', $product->fresh()->stock_quantity);
        $this->assertSame($movements, DB::table('cash_register_movements')->count());
    }

    public function test_finalize_refuses_a_sale_without_items(): void
    {
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/professional/sales/{$this->saleId}/finalize")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Adicione ao menos um item antes de receber.');
    }

    public function test_courtesy_lines_stay_out_of_fiscal_documents(): void
    {
        $productItem = $this->addItem($this->product());
        $this->addSellable('service', $this->makeService(['price' => 60])->id);
        $this->mark($productItem, $this->owner)->assertOk();
        $this->receive('60.00')->assertCreated();

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/professional/sales/{$this->saleId}/fiscal-documents")->assertCreated();
        $this->assertSame(['nfse'], FiscalDocument::where('sale_id', $this->saleId)->pluck('kind')->map->value->all());
    }

    public function test_courtesy_only_sale_skips_the_fiscal_document_without_error(): void
    {
        $this->mark($this->addItem($this->product()), $this->owner)->assertOk();
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/professional/sales/{$this->saleId}/finalize")->assertOk()
            ->assertJsonPath('data.fiscal_pending', []);

        $this->postJson("/api/professional/sales/{$this->saleId}/fiscal-documents")
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('skipped_reason', 'courtesy_only');
        $this->assertSame(0, FiscalDocument::count());
    }

    public function test_returning_a_courtesy_line_restocks_with_zero_refund_and_no_credit(): void
    {
        $product = $this->product();
        $itemId = $this->addItem($product);
        $this->mark($itemId, $this->owner)->assertOk();
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/professional/sales/{$this->saleId}/finalize")->assertOk();

        $this->actingAs($this->receptionist, 'sanctum')->postJson('/api/professional/sale-returns', [
            'sale_id' => $this->saleId, 'reason' => 'Não serviu', 'refund_method' => 'store_credit',
            'items' => [['sale_item_id' => $itemId, 'quantity' => 1]],
        ])->assertCreated()->assertJsonPath('data.total', 0);

        $this->assertSame('10.0000', $product->fresh()->stock_quantity);
        $this->assertSame(0, ClientAccountEntry::where('reference_type', 'sale_return')->count());
    }

    public function test_quote_line_keeps_the_courtesy_through_conversion(): void
    {
        $quoteId = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/professional/quotes', ['client_id' => $this->tutor->id, 'pet_id' => $this->pet->id, 'valid_until' => now()->addDays(10)->toDateString()])
            ->assertCreated()
            ->json('data.id');
        $itemId = $this->postJson("/api/professional/quotes/{$quoteId}/items", ['sellable_type' => 'product', 'sellable_id' => $this->product()->id])
            ->assertCreated()
            ->json('data.id');
        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/professional/sales/{$quoteId}/items/{$itemId}/courtesy", ['kind' => 'courtesy', 'reason' => 'Retorno de cirurgia'])
            ->assertOk();

        $this->openRegisterFor($this->owner);
        $sale = app(SaleService::class)->convertQuoteToSale(Sale::findOrFail($quoteId), $this->owner);

        $copied = SaleItem::where('sale_id', $sale->id)->sole();
        $this->assertSame(['courtesy', 'Retorno de cirurgia', '100.00', $this->owner->id], [
            $copied->courtesy_kind->value, $copied->courtesy_reason, $copied->courtesy_original_unit_price, $copied->courtesy_marked_by,
        ]);
    }

    public function test_quote_prefilled_from_the_appointment_accepts_a_zero_price_line_with_cost(): void
    {
        $followUp = $this->makeService(['name' => 'Retorno', 'price' => 0, 'direct_cost' => '15.00'], by: $this->owner);
        $appointment = Appointment::create([
            'professional_id' => $this->owner->id, 'organization_id' => $this->clinic->id, 'client_id' => $this->tutor->id,
            'pet_id' => $this->pet->id, 'appointment_date' => now()->toDateString(), 'appointment_time' => '09:00',
            'type' => 'consultation', 'status' => 'in_progress',
        ]);
        AppointmentService::create(['appointment_id' => $appointment->id, 'service_id' => $followUp->id, 'quantity' => 1, 'unit_price' => 0]);
        $record = MedicalRecord::create([
            'pet_id' => $this->pet->id, 'professional_id' => $this->owner->id, 'appointment_id' => $appointment->id,
            'record_date' => now()->toDateString(), 'status' => 'draft', 'chief_complaint' => 'lameness',
        ]);

        $quoteId = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/professional/quotes', ['medical_record_id' => $record->id])
            ->assertCreated()
            ->json('data.id');

        $item = SaleItem::where('sale_id', $quoteId)->sole();
        $this->assertSame(['0.00', '15.0000', null], [$item->unit_price, $item->unit_cost, $item->courtesy_kind]);
        $this->assertTrue($item->isBelowCost());
    }

    /** @param  array<string, mixed>  $overrides */
    private function product(array $overrides = []): Product
    {
        return $this->makeProduct($overrides + ['price' => 100]);
    }

    /** @param  array<string, mixed>  $body */
    private function addItem(Product $product, array $body = []): int
    {
        return $this->addSellable('product', $product->id, $body);
    }

    /** @param  array<string, mixed>  $body */
    private function addSellable(string $type, int $id, array $body = []): int
    {
        return $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/professional/sales/{$this->saleId}/items", ['sellable_type' => $type, 'sellable_id' => $id] + $body)
            ->assertCreated()
            ->json('data.id');
    }

    private function mark(int $itemId, User $by, string $kind = 'courtesy'): TestResponse
    {
        return $this->actingAs($by, 'sanctum')
            ->putJson("/api/professional/sales/{$this->saleId}/items/{$itemId}/courtesy", ['kind' => $kind, 'reason' => 'Cliente fiel']);
    }

    private function receive(string $amount): TestResponse
    {
        return $this->actingAs($this->receptionist, 'sanctum')
            ->postJson("/api/professional/sales/{$this->saleId}/receipts", ['payment_method_id' => $this->paymentMethodId($this->receptionist, 'cash'), 'amount' => $amount]);
    }

    /** @return array<int, string> */
    private function netTotals(): array
    {
        $totals = array_map(fn ($money): string => $money->toString(), Sale::with('items')->findOrFail($this->saleId)->netItemTotals());
        ksort($totals);

        return $totals;
    }
}
