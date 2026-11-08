<?php

namespace Tests\Feature\Fiscal\Concerns;

use App\Models\Product;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;

/**
 * Cenários de emissão da Fase 4 fiscal (docs/gap-petflow/specs/fase4-fiscal-sem-envio.md) sobre o
 * balcão padrão (`BuildsCounterFixtures`): venda paga em dinheiro pela recepcionista, emissão
 * pelo dono.
 */
trait BuildsFiscalScenarios
{
    use BuildsCounterFixtures;

    private bool $registerOpen = false;

    /**
     * @param  list<array{0: Product, 1: int|string}>  $lines  produto e quantidade
     * @param  array{discount?: string, operation?: string, client_id?: int|null}  $options
     */
    protected function paidProductSale(array $lines, array $options = []): int
    {
        $this->ensureRegisterOpen();
        $saleId = $this->actingAs($this->receptionist, 'sanctum')
            ->postJson('/api/professional/sales', array_filter([
                'kind' => 'sale',
                'fiscal_operation' => $options['operation'] ?? 'in_person_consumer',
                'client_id' => $options['client_id'] ?? null,
            ]))
            ->assertCreated()->json('data.id');

        foreach ($lines as [$product, $quantity]) {
            $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $product->id, 'quantity' => $quantity])->assertCreated();
        }

        if (isset($options['discount'])) {
            $this->postJson("/api/professional/sales/{$saleId}/discount", ['discount_type' => 'amount', 'discount_value' => $options['discount']])->assertOk();
        }

        $total = $this->getJson("/api/professional/sales/{$saleId}")->assertOk()->json('data.total');
        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $this->paymentMethodId($this->receptionist, 'cash'), 'amount' => $total])
            ->assertCreated()
            ->assertJsonPath('sale.status', 'paid');

        return $saleId;
    }

    protected function issueSale(int $saleId): TestResponse
    {
        return $this->actingAs($this->owner, 'sanctum')->postJson("/api/professional/sales/{$saleId}/fiscal-documents");
    }

    /** Tutor com CPF e endereço completos — exigência da NF-e de devolução. */
    protected function identifyTutor(string $state = 'SP'): void
    {
        $this->tutor->forceFill([
            'cpf' => '52998224725', 'address' => 'Rua das Flores', 'number' => '100', 'neighborhood' => 'Centro',
            'city' => 'São Paulo', 'state' => $state, 'zip_code' => '01001000',
        ])->save();
    }

    /** @param  list<array{0: int, 1: int|string}>  $lines  sale_item_id e quantidade */
    protected function returnSaleItems(int $saleId, array $lines): int
    {
        $this->ensureRegisterOpen();

        return $this->actingAs($this->receptionist, 'sanctum')
            ->postJson('/api/professional/sale-returns', [
                'sale_id' => $saleId, 'reason' => 'Cliente devolveu', 'refund_method' => 'cash',
                'items' => array_map(fn (array $line): array => ['sale_item_id' => $line[0], 'quantity' => $line[1]], $lines),
            ])
            ->assertCreated()
            ->json('data.id');
    }

    private function ensureRegisterOpen(): void
    {
        if ($this->registerOpen) {
            return;
        }

        $this->openRegisterFor($this->receptionist, 500);
        $this->registerOpen = true;
    }
}
