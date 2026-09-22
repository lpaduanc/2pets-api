<?php

namespace Tests\Feature\Commercial\Concerns;

use App\Models\Sale;
use App\Models\User;

/**
 * Orçamento do doc gap-simplesvet/24 em cima do cenário de balcão do doc 01
 * (`BuildsCounterFixtures`): mesma clínica, mesmo tutor, mesmo catálogo.
 */
trait BuildsQuoteFixtures
{
    use BuildsCounterFixtures;

    /**
     * Rascunho com 3 itens: 2 produtos que controlam estoque e 1 serviço. Total 100×2 + 40 + 60 = 300.
     *
     * @return array{id: int, products: array{0: \App\Models\Product, 1: \App\Models\Product}}
     */
    protected function draftQuoteWithThreeItems(?User $by = null): array
    {
        $by ??= $this->receptionist;

        $food = $this->makeProduct(['name' => 'Ração Renal', 'price' => 100, 'stock_quantity' => 10]);
        $collar = $this->makeProduct(['name' => 'Colar elizabetano', 'price' => 40, 'stock_quantity' => 5]);
        $surgery = $this->makeService(['name' => 'Cirurgia de castração', 'price' => 60]);

        $id = $this->actingAs($by, 'sanctum')->postJson('/api/professional/quotes', [
            'client_id' => $this->tutor->id,
            'pet_id' => $this->pet->id,
            'valid_until' => now()->addDays(10)->toDateString(),
            'printed_notes' => 'Inclui retorno em 7 dias.',
            'notes' => 'Tutor pediu parcelamento — interno.',
        ])->assertCreated()->json('data.id');

        foreach ([[$food, 2], [$collar, 1]] as [$product, $quantity]) {
            $this->postJson("/api/professional/quotes/{$id}/items", [
                'sellable_type' => 'product', 'sellable_id' => $product->id, 'quantity' => $quantity,
            ])->assertCreated();
        }

        $this->postJson("/api/professional/quotes/{$id}/items", [
            'sellable_type' => 'service', 'sellable_id' => $surgery->id,
        ])->assertCreated()->assertJsonPath('quote.total', 300);

        return ['id' => $id, 'products' => [$food, $collar]];
    }

    /** Envia e devolve o token em texto puro, lido do `public_url` da resposta. */
    protected function sendQuote(int $id, ?User $by = null): string
    {
        $url = $this->actingAs($by ?? $this->receptionist, 'sanctum')
            ->postJson("/api/professional/quotes/{$id}/send")
            ->assertOk()
            ->assertJsonPath('data.quote_status', 'sent')
            ->json('public_url');

        return basename((string) parse_url($url, PHP_URL_PATH));
    }

    protected function quote(int $id): Sale
    {
        return Sale::findOrFail($id);
    }
}
