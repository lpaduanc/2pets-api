<?php

namespace Tests\Feature\Commercial;

use App\Models\Pet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\Feature\Commercial\Concerns\BuildsPackageFixtures;
use Tests\TestCase;

/**
 * Critérios de aceite do docs/gap-simplesvet/specs/10-pacotes-de-servicos-vendidos-spec.md,
 * regra de negócio 2: pacote é um crédito NOMINAL ao animal, não à carteira do tutor.
 */
class PackageCrossPetTest extends TestCase
{
    use BuildsCounterFixtures;
    use BuildsPackageFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildClinic();
    }

    public function test_a_package_sold_to_one_pet_is_not_available_for_another_pet_by_default(): void
    {
        $package = $this->makeServicePackage();
        $this->sellPackageToPet($package, $this->pet, $this->tutor);

        $otherPet = Pet::factory()->create(['user_id' => $this->tutor->id]);

        $this->getJson("/api/professional/pets/{$this->pet->id}/available-packages")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson("/api/professional/pets/{$otherPet->id}/available-packages")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_transferable_package_is_available_for_another_pet_of_the_same_client(): void
    {
        $package = $this->makeServicePackage(['allow_transfer_between_pets' => true]);
        $this->sellPackageToPet($package, $this->pet, $this->tutor);

        $otherPet = Pet::factory()->create(['user_id' => $this->tutor->id]);

        $this->getJson("/api/professional/pets/{$otherPet->id}/available-packages")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_available_packages_only_returns_active_status_with_remaining_balance(): void
    {
        $package = $this->makeServicePackage(quantity: 1);
        $soldPackage = $this->sellPackageToPet($package, $this->pet, $this->tutor);
        $item = $soldPackage->items()->first();

        $this->postJson("/api/professional/sold-packages/{$soldPackage->id}/consume", [
            'sold_package_item_id' => $item->id, 'quantity' => 1,
        ])->assertOk();

        $this->getJson("/api/professional/pets/{$this->pet->id}/available-packages")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
