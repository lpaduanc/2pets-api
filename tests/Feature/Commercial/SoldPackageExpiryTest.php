<?php

namespace Tests\Feature\Commercial;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\Feature\Commercial\Concerns\BuildsPackageFixtures;
use Tests\TestCase;

/**
 * Critérios de aceite do docs/gap-simplesvet/specs/10-pacotes-de-servicos-vendidos-spec.md:
 * status efetivo derivado em query, nunca reescrito por job (não há scheduler em dev).
 */
class SoldPackageExpiryTest extends TestCase
{
    use BuildsCounterFixtures;
    use BuildsPackageFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildClinic();
    }

    public function test_a_package_past_its_validity_with_balance_shows_as_expired_and_blocks_consumption(): void
    {
        $package = $this->makeServicePackage(quantity: 10);
        $soldPackage = $this->sellPackageToPet($package, $this->pet, $this->tutor);
        $soldPackage->update(['expires_at' => now()->subDay()->toDateString()]);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/professional/sold-packages/{$soldPackage->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'expired');

        $item = $soldPackage->items()->first();
        $this->postJson("/api/professional/sold-packages/{$soldPackage->id}/consume", [
            'sold_package_item_id' => $item->id, 'quantity' => 1,
        ])->assertStatus(422);

        $this->assertSame(0, $item->fresh()->quantity_used);
    }

    public function test_a_fully_used_package_shows_as_consumed_even_with_a_future_expiry(): void
    {
        $package = $this->makeServicePackage(quantity: 1);
        $soldPackage = $this->sellPackageToPet($package, $this->pet, $this->tutor);
        $item = $soldPackage->items()->first();

        $this->postJson("/api/professional/sold-packages/{$soldPackage->id}/consume", [
            'sold_package_item_id' => $item->id, 'quantity' => 1,
        ])->assertOk();

        $this->getJson("/api/professional/sold-packages/{$soldPackage->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'consumed');
    }
}
