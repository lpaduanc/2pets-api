<?php

namespace Tests\Feature\Commercial;

use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\Feature\Commercial\Concerns\BuildsPackageFixtures;
use Tests\TestCase;

/**
 * Critérios de aceite do docs/gap-simplesvet/specs/10-pacotes-de-servicos-vendidos-spec.md:
 * cancelar a venda cancela o pacote e trava consumo futuro — sem endpoint de cancelamento
 * próprio (efeito colateral de `SaleService::cancel()`).
 */
class SoldPackageCancellationTest extends TestCase
{
    use BuildsCounterFixtures;
    use BuildsPackageFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildClinic();
    }

    public function test_cancelling_the_originating_sale_cancels_the_package_and_blocks_consumption(): void
    {
        $package = $this->makeServicePackage();
        $soldPackage = $this->sellPackageToPet($package, $this->pet, $this->tutor);
        $saleId = $soldPackage->sale_id;

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/professional/sales/{$saleId}/cancel", ['reason' => 'Cliente desistiu'])
            ->assertOk();

        $this->assertSame('cancelled', Sale::find($saleId)->status->value);

        $this->getJson("/api/professional/sold-packages/{$soldPackage->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $item = $soldPackage->items()->first();
        $this->postJson("/api/professional/sold-packages/{$soldPackage->id}/consume", [
            'sold_package_item_id' => $item->id, 'quantity' => 1,
        ])->assertStatus(422);
    }
}
