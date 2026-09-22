<?php

namespace Tests\Feature\Stock;

use App\Enums\StockMovementType;
use App\Models\StockMovement;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Stock\Concerns\BuildsStockFixtures;
use Tests\TestCase;

/**
 * Critério de aceite do doc 07: "contagem de inventário fechada gera os ajustes e registra
 * quantos itens estavam corretos e quantos foram corrigidos".
 */
class StockCountTest extends TestCase
{
    use BuildsStockFixtures;
    use RefreshDatabase;

    public function test_close_generates_adjustments_and_counts_correct_and_adjusted_items(): void
    {
        $this->buildStockClinic();
        $stock = app(StockService::class);

        $right = $this->product(['name' => 'Certo', 'gtin' => '7890000000017', 'average_cost' => 5]);
        $short = $this->product(['name' => 'Faltando', 'average_cost' => 10]);
        $extra = $this->product(['name' => 'Sobrando', 'average_cost' => 2]);
        $uncounted = $this->product(['name' => 'Não contado']);
        $this->product(['name' => 'Serviço', 'controls_stock' => false]);

        foreach ([$right, $short, $extra, $uncounted] as $product) {
            $stock->in($product, StockMovementType::OPENING_BALANCE, 10);
        }

        $this->actingAs($this->owner, 'sanctum');
        $count = $this->postJson('/api/professional/stock-counts', ['notes' => 'Inventário mensal'])
            ->assertCreated()
            ->assertJsonPath('data.items_total', 4);
        $countId = $count->json('data.id');

        // Contagem em duas levas.
        $this->putJson("/api/professional/stock-counts/{$countId}/items", ['items' => [
            ['product_id' => $right->id, 'counted_quantity' => 10],
            ['product_id' => $short->id, 'counted_quantity' => 7],
        ]])->assertOk()->assertJsonPath('data.items_counted', 2);

        // Venda durante a contagem: 1 unidade do "Sobrando" sai antes do fechamento.
        $stock->out($extra, StockMovementType::SALE_OUT, 1);

        $this->putJson("/api/professional/stock-counts/{$countId}/items", ['items' => [
            ['product_id' => $extra->id, 'counted_quantity' => 12],
        ]])->assertOk();

        $this->postJson("/api/professional/stock-counts/{$countId}/close")
            ->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.items_correct', 1)
            ->assertJsonPath('data.items_adjusted', 2)
            ->assertJsonPath('data.adjustment_value', -26); // -3×10 + 2×2

        $this->assertSame(10, $right->fresh()->stock_quantity);
        $this->assertSame(7, $short->fresh()->stock_quantity);
        // Diferença (+2) aplicada sobre o saldo atual (9), não o número contado (12).
        $this->assertSame(11, $extra->fresh()->stock_quantity);
        $this->assertSame(10, $uncounted->fresh()->stock_quantity);

        $this->assertSame(2, StockMovement::whereIn('type', ['adjustment_in', 'adjustment_out'])->count());
        foreach ([$right, $short, $extra, $uncounted] as $product) {
            $this->assertLedgerMatches($product);
        }

        $this->putJson("/api/professional/stock-counts/{$countId}/items", ['items' => [
            ['product_id' => $right->id, 'counted_quantity' => 1],
        ]])->assertStatus(422);
        $this->deleteJson("/api/professional/stock-counts/{$countId}")->assertStatus(422);
    }
}
