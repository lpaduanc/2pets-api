<?php

namespace Tests\Feature\Commercial;

use App\Models\CashRegisterMovement;
use App\Models\SaleReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commercial\Concerns\BuildsQuoteFixtures;
use Tests\TestCase;

/**
 * Critério de aceite do doc 24: "Orçamento com 3 itens NÃO movimenta caixa, estoque nem
 * financeiro" — nem ao criar, nem ao enviar, nem ao ser aprovado.
 */
class QuoteNoSideEffectsTest extends TestCase
{
    use BuildsQuoteFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildClinic();
    }

    public function test_a_three_item_quote_moves_no_cash_no_stock_and_no_money_through_its_whole_life(): void
    {
        // Caixa aberto de propósito: o orçamento não pode se pendurar nele.
        $registerId = $this->openRegisterFor($this->receptionist, 50);
        ['id' => $id, 'products' => [$food, $collar]] = $this->draftQuoteWithThreeItems();

        $this->sendQuote($id);
        $this->actingAs($this->tutor, 'sanctum')->postJson("/api/me/quotes/{$id}/approve")->assertOk();

        $quote = $this->quote($id);
        $this->assertNull($quote->cash_register_id);
        $this->assertNull($quote->sold_at);
        $this->assertSame('approved', $quote->quote_status->value);

        $this->assertSame(10, $food->fresh()->stock_quantity);
        $this->assertSame(5, $collar->fresh()->stock_quantity);
        $this->assertSame(0, SaleReceipt::count());
        $this->assertSame(0, CashRegisterMovement::where('cash_register_id', $registerId)->whereIn('type', ['sale_receipt', 'refund'])->count());
    }

    public function test_a_quote_is_created_without_any_open_cash_register(): void
    {
        $this->actingAs($this->receptionist, 'sanctum')
            ->postJson('/api/professional/quotes', ['client_id' => $this->tutor->id])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'quote')
            ->assertJsonPath('data.quote_status', 'draft')
            ->assertJsonPath('data.cash_register_id', null);
    }

    public function test_a_quote_never_takes_a_payment(): void
    {
        $this->openRegisterFor($this->receptionist, 0);
        $cash = $this->paymentMethodId($this->receptionist, 'cash');
        ['id' => $id] = $this->draftQuoteWithThreeItems();

        $this->postJson("/api/professional/sales/{$id}/receipts", ['payment_method_id' => $cash, 'amount' => 300])
            ->assertStatus(422);

        $this->assertSame(0, SaleReceipt::count());
    }
}
