<?php

namespace Tests\Feature\Commercial;

use App\Models\CashRegisterMovement;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commercial\Concerns\BuildsQuoteFixtures;
use Tests\TestCase;

/**
 * Critério de aceite do doc 24: "Converter em venda movimenta os três (caixa, estoque,
 * financeiro), com os mesmos itens e valores".
 */
class QuoteConversionTest extends TestCase
{
    use BuildsQuoteFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildClinic();
    }

    public function test_converting_an_approved_quote_moves_cash_stock_and_receipts_with_the_same_items(): void
    {
        ['id' => $id, 'products' => [$food, $collar]] = $this->draftQuoteWithThreeItems();
        $this->postJson("/api/professional/quotes/{$id}/discount", ['discount_type' => 'amount', 'discount_value' => 20])
            ->assertOk()
            ->assertJsonPath('data.total', 280);
        $this->sendQuote($id);
        $this->actingAs($this->tutor, 'sanctum')->postJson("/api/me/quotes/{$id}/approve")->assertOk();

        $registerId = $this->openRegisterFor($this->receptionist, 0);
        $cash = $this->paymentMethodId($this->receptionist, 'cash');
        $pix = $this->paymentMethodId($this->receptionist, 'pix');

        $response = $this->actingAs($this->receptionist, 'sanctum')
            ->postJson("/api/professional/quotes/{$id}/convert", [
                'receipts' => [
                    ['payment_method_id' => $cash, 'amount' => 80],
                    ['payment_method_id' => $pix, 'amount' => 200],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'sale')
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.total', 280);

        $saleId = $response->json('data.id');

        // Mesmos itens e valores (congelados), mesma ordem.
        $fields = ['sellable_type', 'sellable_id', 'description', 'quantity', 'unit_price', 'discount', 'total'];
        $this->assertEquals(
            SaleItem::where('sale_id', $id)->orderBy('id')->get()->map->only($fields)->all(),
            SaleItem::where('sale_id', $saleId)->orderBy('id')->get()->map->only($fields)->all(),
        );

        // Caixa: dois recebimentos espelhados no caixa aberto de quem converteu.
        $this->assertSame(2, CashRegisterMovement::where('cash_register_id', $registerId)->where('type', 'sale_receipt')->count());
        $this->assertSame($registerId, Sale::find($saleId)->cash_register_id);
        // Financeiro: recebimentos com forma, valor e líquido gravados.
        $this->assertEquals(280, (float) SaleReceipt::where('sale_id', $saleId)->sum('amount'));
        // Estoque: 2 rações e 1 colar saem; o serviço não mexe em nada.
        $this->assertSame(8, $food->fresh()->stock_quantity);
        $this->assertSame(4, $collar->fresh()->stock_quantity);

        // O orçamento continua existindo como documento, apontando para a venda.
        $this->getJson("/api/professional/quotes/{$id}")
            ->assertOk()
            ->assertJsonPath('data.quote_status', 'converted')
            ->assertJsonPath('data.converted_sale.id', $saleId)
            ->assertJsonPath('data.can_convert', false);

        $this->postJson("/api/professional/quotes/{$id}/convert")->assertStatus(422);
    }

    public function test_conversion_without_receipts_leaves_an_open_sale_and_stock_untouched_until_paid(): void
    {
        ['id' => $id, 'products' => [$food]] = $this->draftQuoteWithThreeItems();
        $this->openRegisterFor($this->receptionist, 0);

        // Tutor autorizou no balcão: rascunho converte direto, sem passar pelo app.
        $saleId = $this->actingAs($this->receptionist, 'sanctum')
            ->postJson("/api/professional/quotes/{$id}/convert")
            ->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->json('data.id');

        $this->assertSame(10, $food->fresh()->stock_quantity);
        $this->assertSame(0, SaleReceipt::where('sale_id', $saleId)->count());
    }

    public function test_conversion_requires_an_open_cash_register(): void
    {
        ['id' => $id] = $this->draftQuoteWithThreeItems();

        $this->postJson("/api/professional/quotes/{$id}/convert")
            ->assertStatus(422)
            ->assertJsonPath('code', 'cash_register_required');

        $this->assertSame('draft', $this->quote($id)->quote_status->value);
    }

    public function test_a_rejected_quote_cannot_be_converted(): void
    {
        ['id' => $id] = $this->draftQuoteWithThreeItems();
        $this->sendQuote($id);
        $this->actingAs($this->tutor, 'sanctum')->postJson("/api/me/quotes/{$id}/reject", ['reason' => 'Caro'])->assertOk();
        $this->openRegisterFor($this->receptionist, 0);

        $this->actingAs($this->receptionist, 'sanctum')
            ->postJson("/api/professional/quotes/{$id}/convert")
            ->assertStatus(422)
            ->assertJsonPath('code', 'quote_not_convertible');
    }
}
