<?php

namespace Tests\Feature\Commercial;

use App\Enums\StockMovementType;
use App\Models\Appointment;
use App\Models\AppointmentCharge;
use App\Models\AppointmentService;
use App\Models\MedicalRecord;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Service;
use App\Models\ServiceSupply;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Commercial\SaleService;
use App\Services\Commercial\ServiceCost\ServiceSupplyConsumer;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Ficha técnica de insumos do serviço — docs/gap-petflow/PLANO.md, 1.3 (golden `servico-01` em
 * `tests/Fixtures/golden/service-cost.json`): GET/PUT da ficha, prévia, custo congelado na venda,
 * baixa `internal_use` no atendimento concluído e na venda paga (sem duplicar), estorno no
 * cancelamento/devolução e insumo fora do PDV.
 */
class ServiceSuppliesTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    private Service $bath;

    private Product $shampoo;

    private Product $conditioner;

    private Product $towel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildClinic();

        $this->bath = $this->makeService(['name' => 'Banho porte P', 'price' => 50], by: $this->owner);
        $this->shampoo = $this->supply('Shampoo neutro', 'ML', '1000', '0.08');
        $this->conditioner = $this->supply('Condicionador', 'ML', '1000', '0.10');
        $this->towel = $this->supply('Toalha descartável', 'UN', '100', '1.25');
        $this->actingAs($this->owner, 'sanctum');
    }

    public function test_put_replaces_the_sheet_and_get_returns_golden_servico_01(): void
    {
        $this->putJson("/api/professional/services/{$this->bath->id}/supplies", ['direct_cost' => '15.00', 'items' => [
            ['product_id' => $this->towel->id, 'quantity' => '1'],
        ]])->assertOk();

        $this->putJson("/api/professional/services/{$this->bath->id}/supplies", ['direct_cost' => '0.00'] + $this->servico01Body())
            ->assertOk()
            ->assertJsonPath('data.direct_cost', '0.00')
            ->assertJsonPath('data.supplies_cost', '6.9000')
            ->assertJsonPath('data.unit_cost', '6.9000');

        $this->assertSame(3, ServiceSupply::where('service_id', $this->bath->id)->count());

        $this->getJson("/api/professional/services/{$this->bath->id}/supplies")
            ->assertOk()
            ->assertJsonPath('data.items.0.product_id', $this->shampoo->id)
            ->assertJsonPath('data.items.0.product', ['id' => $this->shampoo->id, 'name' => 'Shampoo neutro', 'unit' => 'ML', 'average_cost' => '0.0800'])
            ->assertJsonPath('data.items.0.quantity', '30.000')
            ->assertJsonPath('data.items.0.unit_cost', '0.0800')
            ->assertJsonPath('data.items.0.line_cost', '2.4000')
            ->assertJsonPath('data.items.2.line_cost', '2.5000')
            ->assertJsonPath('data.supplies_cost', '6.9000')
            ->assertJsonPath('data.unit_cost', '6.9000')
            ->assertJsonPath('data.price', '50.00')
            ->assertJsonPath('data.margin_amount', '43.10')
            ->assertJsonPath('data.margin_percent', '86.2000')
            ->assertJsonPath('data.markup_percent', '624.6377');

        $this->assertSame('6.9000', $this->bath->fresh()->unitCost());
    }

    public function test_supply_bought_by_the_litre_keeps_the_four_place_cost_per_ml(): void
    {
        // 1 L por R$ 83,33 → 0,0833/ml; com 2 casas viraria 0,08 e o banho custaria 2,40.
        $shampoo = $this->supply('Shampoo galão', 'ML', '0', '0');
        app(StockService::class)->in($shampoo, StockMovementType::PURCHASE_IN, '1000', ['total_cost' => '83.33']);
        $this->assertSame('0.0833', $shampoo->fresh()->average_cost);

        $this->putJson("/api/professional/services/{$this->bath->id}/supplies", ['items' => [['product_id' => $shampoo->id, 'quantity' => '30']]])
            ->assertOk()
            ->assertJsonPath('data.items.0.product.average_cost', '0.0833')
            ->assertJsonPath('data.items.0.line_cost', '2.4990')
            ->assertJsonPath('data.unit_cost', '2.4990')
            ->assertJsonPath('data.margin_amount', '47.50');

        [, $item] = $this->sellBath('1');
        $this->assertSame('2.4990', $item->unit_cost);
        // O movimento sai pelo médio de 4 casas; o valor que sai é centavo (30 × 0,0833 = 2,499 → 2,50).
        $movement = $this->movementsOf($item, StockMovementType::INTERNAL_USE)->sole();
        $this->assertSame('0.0833', $movement->unit_cost);
        $this->assertSame('2.50', $movement->total_cost);
    }

    public function test_preview_computes_an_unsaved_sheet_with_direct_cost(): void
    {
        $this->postJson('/api/professional/services/cost-preview', ['price' => '80.00', 'direct_cost' => '15.00'] + $this->servico01Body())
            ->assertOk()
            ->assertJsonPath('data.items.0', ['product_id' => $this->shampoo->id, 'unit_cost' => '0.0800', 'line_cost' => '2.4000'])
            ->assertJsonPath('data.supplies_cost', '6.9000')
            ->assertJsonPath('data.direct_cost', '15.00')
            ->assertJsonPath('data.unit_cost', '21.9000')
            ->assertJsonPath('data.margin_amount', '58.10')
            ->assertJsonPath('data.margin_percent', '72.6250')
            ->assertJsonPath('data.markup_percent', '265.2968');

        $this->postJson('/api/professional/services/cost-preview', ['price' => '0'])
            ->assertOk()
            ->assertJsonPath('data.unit_cost', '0.0000')
            ->assertJsonPath('data.margin_percent', null)
            ->assertJsonPath('data.markup_percent', null);

        $this->assertSame(0, ServiceSupply::count());
    }

    public function test_invalid_sheet_is_refused_with_field_errors(): void
    {
        $otherClinic = Organization::factory()->create();
        $stranger = User::factory()->professional()->create();
        $foreign = $this->makeProduct(['name' => 'Insumo de outra clínica'], $otherClinic, $stranger);

        $this->putJson("/api/professional/services/{$this->bath->id}/supplies", [
            'direct_cost' => '-1',
            'items' => [
                ['product_id' => $foreign->id, 'quantity' => '1'],
                ['product_id' => $this->shampoo->id, 'quantity' => '0'],
                ['product_id' => $this->shampoo->id, 'quantity' => '1.2345'],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'direct_cost', 'items.0.product_id', 'items.1.quantity', 'items.1.product_id', 'items.2.quantity',
        ]);

        $this->postJson('/api/professional/services/cost-preview', ['price' => '10.001', 'items' => [['product_id' => $foreign->id, 'quantity' => '1']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price', 'items.0.product_id']);
    }

    public function test_another_professionals_service_sheet_is_not_found(): void
    {
        $this->actingAs($this->groomer, 'sanctum');

        $this->getJson("/api/professional/services/{$this->bath->id}/supplies")->assertNotFound();
        $this->putJson("/api/professional/services/{$this->bath->id}/supplies", $this->servico01Body())->assertNotFound();
    }

    public function test_direct_cost_is_accepted_and_returned_on_the_service(): void
    {
        $id = $this->postJson('/api/professional/services', [
            'name' => 'Tosa higiênica', 'category' => 'grooming', 'duration' => 30, 'price' => 40, 'direct_cost' => '3.50',
        ])->assertCreated()->assertJsonPath('service.direct_cost', '3.50')->json('service.id');

        $this->putJson("/api/professional/services/{$id}", ['direct_cost' => '4.00'])->assertOk();
        $this->getJson("/api/professional/services/{$id}")->assertOk()->assertJsonPath('direct_cost', '4.00');
        $this->putJson("/api/professional/services/{$id}", ['direct_cost' => '4.001'])->assertUnprocessable()->assertJsonValidationErrors('direct_cost');
    }

    public function test_paid_sale_freezes_the_cost_and_consumes_the_sheet_once(): void
    {
        $this->putJson("/api/professional/services/{$this->bath->id}/supplies", $this->servico01Body())->assertOk();
        [$saleId, $item] = $this->sellBath('1');

        $this->assertSame('6.9000', $item->unit_cost);
        $this->assertStock(['shampoo' => '970.0000', 'conditioner' => '980.0000', 'towel' => '98.0000']);
        $this->assertSame(3, $this->movementsOf($item, StockMovementType::INTERNAL_USE)->count());
        $this->assertSame('6.90', (string) $this->movementsOf($item, StockMovementType::INTERNAL_USE)->sum('total_cost'));

        // Ficha mudou depois: o custo congelado e a baixa já feita não mudam; rodar de novo não duplica.
        $this->putJson("/api/professional/services/{$this->bath->id}/supplies", ['items' => []])->assertOk();
        app(ServiceSupplyConsumer::class)->consumeForSaleItem($item->fresh('sellable'), ['user' => $this->owner]);
        $this->assertSame('6.9000', $item->fresh()->unit_cost);
        $this->assertSame(3, $this->movementsOf($item, StockMovementType::INTERNAL_USE)->count());
        $this->assertNotNull($saleId);
    }

    public function test_cancelling_the_sale_returns_the_supplies_at_the_value_they_left(): void
    {
        $this->putJson("/api/professional/services/{$this->bath->id}/supplies", $this->servico01Body())->assertOk();
        [$saleId, $item] = $this->sellBath('1');

        $this->postJson("/api/professional/sales/{$saleId}/cancel", ['reason' => 'Lançado por engano'])->assertOk();

        $this->assertStock(['shampoo' => '1000.0000', 'conditioner' => '1000.0000', 'towel' => '100.0000']);
        $this->assertSame('80.00', $this->shampoo->fresh()->stock_value);
        $this->assertSame('6.90', (string) $this->movementsOf($item, StockMovementType::RETURN_IN)->sum('total_cost'));
    }

    public function test_partial_return_then_cancel_restores_exactly_what_was_consumed(): void
    {
        $this->putJson("/api/professional/services/{$this->bath->id}/supplies", $this->servico01Body())->assertOk();
        [$saleId, $item] = $this->sellBath('3');
        $this->assertStock(['shampoo' => '910.0000', 'towel' => '94.0000']);

        $this->postJson('/api/professional/sale-returns', [
            'sale_id' => $saleId, 'reason' => 'Um banho não foi feito', 'refund_method' => 'none',
            'items' => [['sale_item_id' => $item->id, 'quantity' => 1]],
        ])->assertCreated();
        $this->assertStock(['shampoo' => '940.0000', 'towel' => '96.0000']);

        $this->postJson("/api/professional/sales/{$saleId}/cancel", ['reason' => 'Cliente desistiu'])->assertOk();
        $this->assertStock(['shampoo' => '1000.0000', 'conditioner' => '1000.0000', 'towel' => '100.0000']);
        $this->assertSame('125.00', $this->towel->fresh()->stock_value);
    }

    public function test_completing_the_appointment_consumes_services_and_charges_once_even_without_stock(): void
    {
        $this->putJson("/api/professional/services/{$this->bath->id}/supplies", $this->servico01Body())->assertOk();
        $empty = $this->supply('Perfume pet', 'ML', '0', '0.50');
        $perfume = $this->makeService(['name' => 'Perfume', 'price' => 10], by: $this->owner);
        $this->putJson("/api/professional/services/{$perfume->id}/supplies", ['items' => [['product_id' => $empty->id, 'quantity' => '5']]])->assertOk();

        $appointment = $this->appointmentInProgress();
        AppointmentService::create(['appointment_id' => $appointment->id, 'service_id' => $this->bath->id, 'quantity' => 1, 'unit_price' => 50]);
        AppointmentCharge::create(['appointment_id' => $appointment->id, 'service_id' => $perfume->id, 'description' => 'Perfume', 'quantity' => 1, 'unit_price' => 10, 'added_by' => $this->owner->id]);

        $this->putJson("/api/professional/appointments/{$appointment->id}", ['status' => 'completed'])->assertOk();
        $this->putJson("/api/professional/appointments/{$appointment->id}", ['status' => 'completed'])->assertOk();
        app(ServiceSupplyConsumer::class)->consumeForAppointment($appointment->fresh(), $this->owner->id);

        $this->assertSame(4, $this->movementsOf($appointment, StockMovementType::INTERNAL_USE)->count());
        $this->assertStock(['shampoo' => '970.0000', 'towel' => '98.0000']);
        // Nunca bloqueia o ato: sem saldo, o estoque fica negativo e o atendimento conclui.
        $this->assertSame('-5.0000', $empty->fresh()->stock_quantity);
        $this->assertSame('completed', $appointment->fresh()->status);
    }

    public function test_service_item_linked_to_an_appointment_consumes_only_through_the_appointment(): void
    {
        $this->putJson("/api/professional/services/{$this->bath->id}/supplies", $this->servico01Body())->assertOk();
        $appointment = $this->appointmentInProgress();
        AppointmentService::create(['appointment_id' => $appointment->id, 'service_id' => $this->bath->id, 'quantity' => 1, 'unit_price' => 50]);

        [$saleId, $item] = $this->sellBath('1', $appointment->id);
        $this->assertSame($appointment->id, $item->appointment_id);
        $this->assertSame(0, $this->movementsOf($item, StockMovementType::INTERNAL_USE)->count());
        $this->assertStock(['shampoo' => '1000.0000']);

        $this->putJson("/api/professional/appointments/{$appointment->id}", ['status' => 'completed'])->assertOk();
        $this->assertStock(['shampoo' => '970.0000', 'towel' => '98.0000']);

        // Cancelar a venda não devolve o que o atendimento consumiu.
        $this->postJson("/api/professional/sales/{$saleId}/cancel", ['reason' => 'Cobrança refeita'])->assertOk();
        $this->assertStock(['shampoo' => '970.0000', 'towel' => '98.0000']);
        $this->assertSame(0, $this->movementsOf($item, StockMovementType::RETURN_IN)->count());
    }

    public function test_appointment_link_must_be_from_the_same_clinic_and_only_on_services(): void
    {
        $this->openRegisterFor($this->owner);
        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale'])->assertCreated()->json('data.id');
        $stranger = User::factory()->professional()->create();
        $foreign = Appointment::create([
            'professional_id' => $stranger->id, 'client_id' => $this->tutor->id, 'pet_id' => $this->pet->id,
            'appointment_date' => now()->toDateString(), 'appointment_time' => '10:00', 'type' => 'grooming', 'status' => 'in_progress',
        ]);
        $own = $this->appointmentInProgress();

        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'service', 'sellable_id' => $this->bath->id, 'appointment_id' => $foreign->id])
            ->assertUnprocessable()->assertJsonValidationErrors('appointment_id');
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $this->makeProduct()->id, 'appointment_id' => $own->id])
            ->assertUnprocessable()->assertJsonValidationErrors('appointment_id');
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'service', 'sellable_id' => $this->bath->id, 'appointment_id' => $own->id])
            ->assertCreated()->assertJsonPath('data.appointment_id', $own->id);
    }

    public function test_quote_from_the_medical_record_links_items_to_the_appointment_and_conversion_keeps_it(): void
    {
        $this->openRegisterFor($this->owner);
        $appointment = $this->appointmentInProgress();
        AppointmentService::create(['appointment_id' => $appointment->id, 'service_id' => $this->bath->id, 'quantity' => 1, 'unit_price' => 50]);
        $record = MedicalRecord::create([
            'pet_id' => $this->pet->id, 'professional_id' => $this->owner->id, 'appointment_id' => $appointment->id,
            'record_date' => now()->toDateString(), 'status' => 'draft', 'chief_complaint' => 'lameness',
        ]);

        $quoteId = $this->postJson('/api/professional/quotes', ['medical_record_id' => $record->id])->assertCreated()->json('data.id');
        $this->assertSame([$appointment->id], SaleItem::where('sale_id', $quoteId)->pluck('appointment_id')->all());

        $sale = app(SaleService::class)->convertQuoteToSale(Sale::findOrFail($quoteId), $this->owner);
        $this->assertSame([$appointment->id], SaleItem::where('sale_id', $sale->id)->pluck('appointment_id')->all());
    }

    public function test_supply_is_hidden_from_the_counter_catalog_but_listed_in_stock(): void
    {
        $this->makeProduct(['name' => 'Shampoo revenda']);

        $catalog = collect($this->getJson('/api/professional/sales/catalog?type=product&search=Shampoo')->assertOk()->json('data'));
        $this->assertSame(['Shampoo revenda'], $catalog->pluck('name')->all());

        $supplies = collect($this->getJson('/api/professional/products?is_supply=1')->assertOk()->json('data'));
        $this->assertEqualsCanonicalizing([$this->shampoo->id, $this->conditioner->id, $this->towel->id], $supplies->pluck('id')->all());
        $this->assertTrue($supplies->every(fn (array $product): bool => $product['is_supply'] === true));
        $this->assertCount(4, $this->getJson('/api/professional/products')->json('data'));
    }

    public function test_is_supply_is_accepted_on_create_and_update(): void
    {
        $id = $this->postJson('/api/professional/products', ['name' => 'Algodão', 'sku' => 'ALG-01', 'price' => 5, 'is_supply' => true])
            ->assertCreated()->assertJsonPath('data.is_supply', true)->json('data.id');

        $this->putJson("/api/professional/products/{$id}", ['is_supply' => false])->assertOk()->assertJsonPath('data.is_supply', false);
    }

    /** @return array{items: list<array{product_id: int, quantity: string}>} */
    private function servico01Body(): array
    {
        return ['items' => [
            ['product_id' => $this->shampoo->id, 'quantity' => '30'],
            ['product_id' => $this->conditioner->id, 'quantity' => '20'],
            ['product_id' => $this->towel->id, 'quantity' => '2'],
        ]];
    }

    private function supply(string $name, string $unit, string $stock, string $cost): Product
    {
        $product = $this->makeProduct([
            'name' => $name, 'unit_of_sale' => $unit, 'decimal_places_allowed' => $unit === 'UN' ? 0 : 3,
            'stock_quantity' => 0, 'average_cost' => $cost, 'is_supply' => true, 'purpose' => 'internal_use',
        ], by: $this->owner);

        if ($stock !== '0') {
            app(StockService::class)->in($product, StockMovementType::OPENING_BALANCE, $stock, ['unit_cost' => $cost]);
        }

        return $product->fresh();
    }

    /** @return array{0: int, 1: SaleItem} */
    private function sellBath(string $quantity, ?int $appointmentId = null): array
    {
        $this->openRegisterFor($this->owner);
        $cash = $this->paymentMethodId($this->owner, 'cash');
        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale', 'client_id' => $this->tutor->id])->assertCreated()->json('data.id');
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'service', 'sellable_id' => $this->bath->id, 'quantity' => $quantity, 'appointment_id' => $appointmentId])->assertCreated();
        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $cash, 'amount' => 50 * (int) $quantity])
            ->assertCreated()->assertJsonPath('sale.status', 'paid');

        return [$saleId, SaleItem::where('sale_id', $saleId)->sole()];
    }

    private function appointmentInProgress(): Appointment
    {
        return Appointment::create([
            'professional_id' => $this->owner->id,
            'organization_id' => $this->clinic->id,
            'client_id' => $this->tutor->id,
            'pet_id' => $this->pet->id,
            'appointment_date' => now()->toDateString(),
            'appointment_time' => '09:00',
            'type' => 'grooming',
            'status' => 'in_progress',
        ]);
    }

    /** @param  array<string, string>  $expected */
    private function assertStock(array $expected): void
    {
        foreach ($expected as $property => $quantity) {
            $this->assertSame($quantity, $this->{$property}->fresh()->stock_quantity, $property);
        }
    }

    private function movementsOf(\Illuminate\Database\Eloquent\Model $reference, StockMovementType $type): \Illuminate\Database\Eloquent\Builder
    {
        return StockMovement::query()
            ->where('reference_type', $reference->getMorphClass())
            ->where('reference_id', $reference->getKey())
            ->where('type', $type->value);
    }
}
