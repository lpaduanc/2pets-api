<?php

namespace Tests\Feature\Commercial;

use App\Models\CashRegisterMovement;
use App\Models\Organization;
use App\Models\Pet;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Venda, orçamento e consulta de vendas — critérios de aceite do
 * docs/gap-simplesvet/01-caixa-pdv.md.
 */
class SaleTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildClinic();
    }

    public function test_a_sale_requires_an_open_register_but_a_quote_does_not(): void
    {
        $this->actingAs($this->receptionist, 'sanctum')
            ->postJson('/api/professional/sales', ['kind' => 'sale'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'cash_register_required');

        $this->postJson('/api/professional/sales', ['kind' => 'quote'])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'quote')
            ->assertJsonPath('data.cash_register_id', null);
    }

    public function test_a_sale_goes_into_the_sellers_own_register_and_gets_a_sequential_number(): void
    {
        $registerId = $this->openRegisterFor($this->receptionist);

        $first = $this->postJson('/api/professional/sales', ['kind' => 'sale'])->assertCreated();
        $second = $this->postJson('/api/professional/sales', ['kind' => 'sale'])->assertCreated();

        $first->assertJsonPath('data.cash_register_id', $registerId)->assertJsonPath('data.number', 1);
        $second->assertJsonPath('data.number', 2);
    }

    public function test_the_client_can_be_a_client_of_any_team_member_and_the_pet_must_be_theirs(): void
    {
        $this->openRegisterFor($this->receptionist);

        $this->postJson('/api/professional/sales', [
            'kind' => 'sale', 'client_id' => $this->tutor->id, 'pet_id' => $this->pet->id,
        ])->assertCreated()->assertJsonPath('data.client.name', 'Tutora Balcão');

        $strangersPet = Pet::factory()->create(['user_id' => User::factory()->tutor()->create()->id]);
        $this->postJson('/api/professional/sales', [
            'kind' => 'sale', 'client_id' => $this->tutor->id, 'pet_id' => $strangersPet->id,
        ])->assertStatus(422)->assertJsonValidationErrors('pet_id');
    }

    public function test_a_user_who_is_not_a_client_of_the_clinic_cannot_be_attached(): void
    {
        $this->openRegisterFor($this->receptionist);
        $stranger = User::factory()->tutor()->create();

        $this->postJson('/api/professional/sales', ['kind' => 'sale', 'client_id' => $stranger->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('client_id');
    }

    public function test_items_from_another_clinic_cannot_be_sold(): void
    {
        $this->openRegisterFor($this->receptionist);
        $otherClinic = Organization::factory()->create();
        $otherOwner = User::factory()->professional()->create();
        $foreignProduct = $this->makeProduct(['name' => 'Produto alheio'], $otherClinic, $otherOwner);

        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale'])->json('data.id');

        $this->postJson("/api/professional/sales/{$saleId}/items", [
            'sellable_type' => 'product', 'sellable_id' => $foreignProduct->id,
        ])->assertStatus(422)->assertJsonValidationErrors('sellable_id');

        $this->assertSame(10, $foreignProduct->fresh()->stock_quantity);
    }

    public function test_staff_must_belong_to_the_clinic(): void
    {
        $this->openRegisterFor($this->receptionist);
        $product = $this->makeProduct();
        $outsiderMembership = \App\Models\OrganizationMember::factory()->create();

        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale'])->json('data.id');

        $this->postJson("/api/professional/sales/{$saleId}/items", [
            'sellable_type' => 'product', 'sellable_id' => $product->id, 'staff_id' => $outsiderMembership->id,
        ])->assertStatus(422)->assertJsonValidationErrors('staff_id');
    }

    public function test_price_override_is_refused_when_the_product_does_not_allow_it(): void
    {
        $this->openRegisterFor($this->receptionist);
        $product = $this->makeProduct(['allow_price_override' => false]);
        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale'])->json('data.id');

        $this->postJson("/api/professional/sales/{$saleId}/items", [
            'sellable_type' => 'product', 'sellable_id' => $product->id, 'unit_price' => 50,
        ])->assertStatus(422);
    }

    public function test_percent_discount_is_reapplied_when_an_item_is_added(): void
    {
        $this->openRegisterFor($this->receptionist);
        $product = $this->makeProduct(['price' => 100]);
        $service = $this->makeService(['price' => 60]);
        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale'])->json('data.id');

        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $product->id])->assertCreated();
        $this->postJson("/api/professional/sales/{$saleId}/discount", ['discount_type' => 'percent', 'discount_value' => 10])
            ->assertOk()
            ->assertJsonPath('data.total', 90);

        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'service', 'sellable_id' => $service->id])
            ->assertCreated()
            ->assertJsonPath('sale.subtotal', 160)
            ->assertJsonPath('sale.discount_amount', 16)
            ->assertJsonPath('sale.total', 144);
    }

    public function test_quote_does_not_move_cash_or_stock_and_conversion_does(): void
    {
        $product = $this->makeProduct(['price' => 100, 'stock_quantity' => 10]);

        $quoteId = $this->actingAs($this->receptionist, 'sanctum')
            ->postJson('/api/professional/sales', ['kind' => 'quote'])->json('data.id');
        $this->postJson("/api/professional/sales/{$quoteId}/items", [
            'sellable_type' => 'product', 'sellable_id' => $product->id, 'quantity' => 2,
        ])->assertCreated();

        $this->assertSame(0, CashRegisterMovement::count());
        $this->assertSame(10, $product->fresh()->stock_quantity);

        // Orçamento não recebe pagamento.
        $registerId = $this->openRegisterFor($this->receptionist, 0);
        $cash = $this->paymentMethodId($this->receptionist, 'cash');
        $this->postJson("/api/professional/sales/{$quoteId}/receipts", ['payment_method_id' => $cash, 'amount' => 200])
            ->assertStatus(422);

        $saleId = $this->postJson("/api/professional/sales/{$quoteId}/convert")
            ->assertCreated()
            ->assertJsonPath('data.kind', 'sale')
            ->assertJsonPath('data.total', 200)
            ->json('data.id');

        $this->assertSame($saleId, Sale::find($quoteId)->converted_to_sale_id);

        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $cash, 'amount' => 200])
            ->assertCreated()
            ->assertJsonPath('sale.status', 'paid');

        $this->assertSame(8, $product->fresh()->stock_quantity);
        $this->assertSame(1, CashRegisterMovement::where('cash_register_id', $registerId)->where('type', 'sale_receipt')->count());
    }

    public function test_receipt_larger_than_the_amount_due_is_refused(): void
    {
        $this->openRegisterFor($this->receptionist, 0);
        $product = $this->makeProduct(['price' => 100]);
        $cash = $this->paymentMethodId($this->receptionist, 'cash');
        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale'])->json('data.id');
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $product->id]);

        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $cash, 'amount' => 150])
            ->assertStatus(422);
    }

    public function test_cancelling_a_paid_sale_refunds_the_register_and_restores_stock_and_is_owner_only(): void
    {
        $registerId = $this->openRegisterFor($this->receptionist, 0);
        $product = $this->makeProduct(['price' => 100, 'stock_quantity' => 5]);
        $cash = $this->paymentMethodId($this->receptionist, 'cash');
        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale'])->json('data.id');
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $product->id]);
        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $cash, 'amount' => 100])->assertCreated();
        $this->assertSame(4, $product->fresh()->stock_quantity);

        $this->postJson("/api/professional/sales/{$saleId}/cancel", ['reason' => 'Cliente desistiu'])->assertForbidden();

        $this->openRegisterFor($this->owner, 0);
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/professional/sales/{$saleId}/cancel", ['reason' => 'Cliente desistiu'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(5, $product->fresh()->stock_quantity);
        // Estorno sai da gaveta de quem devolveu o dinheiro (o dono), não da recepcionista.
        $refund = CashRegisterMovement::where('type', 'refund')->sole();
        $this->assertNotSame($registerId, $refund->cash_register_id);
    }

    public function test_client_can_be_changed_even_after_the_sale_is_paid(): void
    {
        $this->openRegisterFor($this->receptionist, 0);
        $service = $this->makeService(['price' => 60]);
        $pix = $this->paymentMethodId($this->receptionist, 'pix');
        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale'])->json('data.id');
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'service', 'sellable_id' => $service->id]);
        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $pix, 'amount' => 60])->assertCreated();

        $this->patchJson("/api/professional/sales/{$saleId}", ['client_id' => $this->tutor->id, 'pet_id' => $this->pet->id])
            ->assertOk()
            ->assertJsonPath('data.client_id', $this->tutor->id)
            ->assertJsonPath('data.pet_id', $this->pet->id);

        // Já os dados impressos só mudam enquanto a venda é editável.
        $this->patchJson("/api/professional/sales/{$saleId}", ['printed_notes' => 'Depois de pago'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'sale_not_editable');
    }

    public function test_sales_list_filters_by_status_staff_item_type_and_fiscal_pending(): void
    {
        $this->openRegisterFor($this->receptionist, 0);
        $product = $this->makeProduct(['price' => 100]);
        $service = $this->makeService(['price' => 60]);
        $cash = $this->paymentMethodId($this->receptionist, 'cash');

        // Venda A: produto, paga, balconista = recepcionista → pendente de NFC-e.
        $a = $this->postJson('/api/professional/sales', ['kind' => 'sale'])->json('data.id');
        $this->postJson("/api/professional/sales/{$a}/items", ['sellable_type' => 'product', 'sellable_id' => $product->id, 'staff_id' => $this->receptionistMember->id]);
        $this->postJson("/api/professional/sales/{$a}/receipts", ['payment_method_id' => $cash, 'amount' => 100])->assertCreated();

        // Venda B: serviço da groomer, não paga.
        $b = $this->postJson('/api/professional/sales', ['kind' => 'sale'])->json('data.id');
        $this->postJson("/api/professional/sales/{$b}/items", ['sellable_type' => 'service', 'sellable_id' => $service->id, 'staff_id' => $this->groomerMember->id]);

        // Venda C: produto para revenda, paga → pendente de NF-e.
        $c = $this->postJson('/api/professional/sales', ['kind' => 'sale', 'fiscal_operation' => 'in_person_resale'])->json('data.id');
        $this->postJson("/api/professional/sales/{$c}/items", ['sellable_type' => 'product', 'sellable_id' => $product->id]);
        $this->postJson("/api/professional/sales/{$c}/receipts", ['payment_method_id' => $cash, 'amount' => 100])->assertCreated();

        $ids = fn (string $query): array => collect($this->getJson('/api/professional/sales?'.$query)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$a, $c], $ids('status[]=paid'));
        $this->assertSame([$b], $ids('status[]=open&status[]=unpaid'));
        $this->assertSame([$b], $ids("staff_id={$this->groomerMember->id}"));
        $this->assertSame([$b], $ids('item_type=service'));
        $this->assertSame([$a], $ids('fiscal_pending=nfce'));
        $this->assertSame([$c], $ids('fiscal_pending=nfe'));
        $this->assertSame([$b], $ids('fiscal_pending=none'));

        $this->getJson("/api/professional/sales/{$a}")->assertJsonPath('data.fiscal_pending', ['nfce']);
    }

    public function test_form_options_catalog_and_clients_are_scoped_to_the_clinic(): void
    {
        $this->makeProduct(['name' => 'Ração da casa']);
        $this->makeService(['name' => 'Banho da casa']);
        $this->makeProduct(['name' => 'Ração alheia'], Organization::factory()->create(), User::factory()->professional()->create());
        User::factory()->tutor()->create(['name' => 'Tutora Balcão Estranha']);

        $this->actingAs($this->receptionist, 'sanctum');

        $staff = collect($this->getJson('/api/professional/sales/form-options')->assertOk()->json('data.staff'));
        $this->assertEqualsCanonicalizing(
            [$this->ownerMember->id, $this->receptionistMember->id, $this->groomerMember->id],
            $staff->pluck('id')->all()
        );

        $catalog = collect($this->getJson('/api/professional/sales/catalog?search=da')->assertOk()->json('data'));
        $this->assertEqualsCanonicalizing(['Ração da casa', 'Banho da casa'], $catalog->pluck('name')->all());

        $clients = $this->getJson('/api/professional/sales/clients?q=Balc')->assertOk()->json('data');
        $this->assertCount(1, $clients);
        $this->assertSame($this->tutor->id, $clients[0]['id']);
        $this->assertSame($this->pet->id, $clients[0]['pets'][0]['id']);
    }

    public function test_sale_from_another_clinic_is_not_visible(): void
    {
        $this->openRegisterFor($this->receptionist);
        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale'])->json('data.id');

        $this->actingAs(User::factory()->professional()->create(), 'sanctum')
            ->getJson("/api/professional/sales/{$saleId}")
            ->assertNotFound();
    }
}
