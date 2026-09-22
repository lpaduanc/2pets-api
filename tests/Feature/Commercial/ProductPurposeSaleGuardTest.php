<?php

namespace Tests\Feature\Commercial;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Critério de aceite do docs/gap-simplesvet/specs/08-produtos-precificacao-lista-precos-spec.md,
 * regra de negócio 2: item de uso interno/consumível nunca pode ser vendido pelo balcão, mesmo
 * mandando o id certo direto na requisição.
 */
class ProductPurposeSaleGuardTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildClinic();
    }

    public function test_an_internal_use_product_cannot_be_added_to_a_sale(): void
    {
        $this->openRegisterFor($this->receptionist);
        $syringe = $this->makeProduct(['name' => 'Seringa 5ml', 'purpose' => 'internal_use']);

        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale'])->json('data.id');

        $this->postJson("/api/professional/sales/{$saleId}/items", [
            'sellable_type' => 'product', 'sellable_id' => $syringe->id,
        ])->assertStatus(422)->assertJsonValidationErrors('sellable_id');
    }

    public function test_a_consumable_product_cannot_be_added_to_a_sale(): void
    {
        $this->openRegisterFor($this->receptionist);
        $cotton = $this->makeProduct(['name' => 'Algodão', 'purpose' => 'consumable']);

        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale'])->json('data.id');

        $this->postJson("/api/professional/sales/{$saleId}/items", [
            'sellable_type' => 'product', 'sellable_id' => $cotton->id,
        ])->assertStatus(422)->assertJsonValidationErrors('sellable_id');
    }

    public function test_a_resale_product_can_still_be_added_to_a_sale(): void
    {
        $this->openRegisterFor($this->receptionist);
        $food = $this->makeProduct(['purpose' => 'resale']);

        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale'])->json('data.id');

        $this->postJson("/api/professional/sales/{$saleId}/items", [
            'sellable_type' => 'product', 'sellable_id' => $food->id,
        ])->assertCreated();
    }
}
