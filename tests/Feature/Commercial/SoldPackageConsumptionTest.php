<?php

namespace Tests\Feature\Commercial;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\Feature\Commercial\Concerns\BuildsPackageFixtures;
use Tests\TestCase;

/**
 * Critérios de aceite do docs/gap-simplesvet/specs/10-pacotes-de-servicos-vendidos-spec.md:
 * baixa de sessão via `POST sold-packages/{id}/consume`.
 */
class SoldPackageConsumptionTest extends TestCase
{
    use BuildsCounterFixtures;
    use BuildsPackageFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildClinic();
    }

    public function test_consuming_one_session_reduces_the_balance_and_logs_who_gave_it(): void
    {
        $package = $this->makeServicePackage(quantity: 10);
        $soldPackage = $this->sellPackageToPet($package, $this->pet, $this->tutor);
        $item = $soldPackage->items()->first();

        $this->actingAs($this->groomer, 'sanctum')
            ->postJson("/api/professional/sold-packages/{$soldPackage->id}/consume", [
                'sold_package_item_id' => $item->id,
                'quantity' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.total_remaining', 9);

        $item->refresh();
        $this->assertSame(1, $item->quantity_used);
        $this->assertSame(1, $item->consumptions()->count());
        $this->assertSame($this->groomer->id, $item->consumptions()->first()->user_id);
    }

    public function test_consuming_more_than_the_balance_is_refused_and_does_not_change_it(): void
    {
        $package = $this->makeServicePackage(quantity: 10);
        $soldPackage = $this->sellPackageToPet($package, $this->pet, $this->tutor);
        $item = $soldPackage->items()->first();

        $this->actingAs($this->groomer, 'sanctum')
            ->postJson("/api/professional/sold-packages/{$soldPackage->id}/consume", [
                'sold_package_item_id' => $item->id,
                'quantity' => 11,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('quantity');

        $this->assertSame(0, $item->fresh()->quantity_used);
    }
}
