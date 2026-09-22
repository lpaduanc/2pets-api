<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
use App\Models\Organization;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Vaccination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Substitui `VaccinationInventoryDeductionTest` (removido na consolidação de estoque —
 * docs/gap-simplesvet/specs/produtos-estoque-consolidado-spec.md). Vínculo OPCIONAL entre
 * vacina/vermífugo e `products`/`product_batches`: sem `product_id` (o caso normal) nunca mexe
 * em estoque; com `product_id`, a baixa é atômica — saldo insuficiente e lote vencido sem
 * confirmação revertem a transação inteira, nem o registro clínico nem o movimento ficam
 * gravados.
 */
class VaccinationProductStockDeductionTest extends TestCase
{
    use RefreshDatabase;

    private User $tutor;

    private User $vet;

    private Pet $pet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tutor = User::factory()->tutor()->create();
        $this->vet = User::factory()->professional()->create();
        $this->pet = Pet::factory()->create(['user_id' => $this->tutor->id]);

        PetVetAccess::create([
            'pet_id' => $this->pet->id,
            'veterinarian_id' => $this->vet->id,
            'granted_by' => $this->tutor->id,
            'access_level' => VetAccessLevel::WRITE,
            'status' => PetVetAccess::STATUS_ACCEPTED,
            'is_active' => true,
            'granted_at' => now(),
            'responded_at' => now(),
        ]);

        Sanctum::actingAs($this->vet);
    }

    public function test_registering_a_vaccination_without_product_id_never_touches_stock(): void
    {
        $product = $this->createStockedProduct(quantity: 5);

        $response = $this->postJson("/api/pets/{$this->pet->id}/health/vaccinations", [
            'vaccine_name' => 'V10',
            'application_date' => now()->toDateString(),
        ]);

        $response->assertCreated();
        $this->assertSame(5, $product->fresh()->stock_quantity);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_registering_a_vaccination_with_product_id_decrements_stock_and_logs_movement(): void
    {
        $product = $this->createStockedProduct(quantity: 1);

        $response = $this->postJson("/api/pets/{$this->pet->id}/health/vaccinations", [
            'vaccine_name' => 'V10',
            'application_date' => now()->toDateString(),
            'product_id' => $product->id,
        ]);

        $response->assertCreated();
        $this->assertSame(0, $product->fresh()->stock_quantity);

        $vaccination = Vaccination::where('pet_id', $this->pet->id)->firstOrFail();
        $this->assertSame($product->id, $vaccination->product_id);

        $this->assertDatabaseCount('stock_movements', 1);
        $movement = StockMovement::firstOrFail();
        $this->assertSame('internal_use', $movement->type->value);
        $this->assertSame('out', $movement->direction->value);
        $this->assertSame(1, $movement->quantity);
        $this->assertSame(Vaccination::class, $movement->reference_type);
        $this->assertSame($vaccination->id, $movement->reference_id);
    }

    public function test_insufficient_stock_returns_422_and_rolls_back_everything(): void
    {
        $product = $this->createStockedProduct(quantity: 0);

        $response = $this->postJson("/api/pets/{$this->pet->id}/health/vaccinations", [
            'vaccine_name' => 'V10',
            'application_date' => now()->toDateString(),
            'product_id' => $product->id,
        ]);

        $response->assertStatus(422)->assertJsonPath('code', 'insufficient_stock');

        $this->assertDatabaseCount('vaccinations', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame(0, $product->fresh()->stock_quantity);
    }

    public function test_expired_batch_requires_explicit_confirmation(): void
    {
        $product = $this->createStockedProduct(quantity: 3, trackBatches: true);
        $batch = ProductBatch::create([
            'product_id' => $product->id,
            'batch_code' => 'LOTE-VENCIDO',
            'expires_at' => now()->subDay(),
            'quantity' => 3,
        ]);

        $blocked = $this->postJson("/api/pets/{$this->pet->id}/health/vaccinations", [
            'vaccine_name' => 'V10',
            'application_date' => now()->toDateString(),
            'product_id' => $product->id,
            'product_batch_id' => $batch->id,
        ]);

        $blocked->assertStatus(422)->assertJsonPath('code', 'expired_batch');
        $this->assertDatabaseCount('vaccinations', 0);
        $this->assertSame(3, $batch->fresh()->quantity);

        $confirmed = $this->postJson("/api/pets/{$this->pet->id}/health/vaccinations", [
            'vaccine_name' => 'V10',
            'application_date' => now()->toDateString(),
            'product_id' => $product->id,
            'product_batch_id' => $batch->id,
            'confirm_expired' => true,
        ]);

        $confirmed->assertCreated();
        $this->assertSame(2, $batch->fresh()->quantity);
    }

    public function test_deworming_follows_the_same_stock_rule_as_vaccination(): void
    {
        $product = $this->createStockedProduct(quantity: 1);

        $response = $this->postJson("/api/pets/{$this->pet->id}/health/dewormings", [
            'product_name' => 'Drontal',
            'applied_date' => now()->toDateString(),
            'product_id' => $product->id,
        ]);

        $response->assertCreated();
        $this->assertSame(0, $product->fresh()->stock_quantity);

        $movement = StockMovement::firstOrFail();
        $this->assertSame('internal_use', $movement->type->value);
    }

    public function test_vet_cannot_link_stock_from_another_organizations_product(): void
    {
        $otherOrgProduct = Product::create([
            'professional_id' => User::factory()->professional()->create()->id,
            'organization_id' => Organization::factory()->create()->id,
            'name' => 'Vacina de outra clínica',
            'sku' => 'OUTRA-CLINICA-1',
            'purpose' => 'consumable',
            'controls_stock' => true,
            'stock_quantity' => 10,
        ]);

        $response = $this->postJson("/api/pets/{$this->pet->id}/health/vaccinations", [
            'vaccine_name' => 'V10',
            'application_date' => now()->toDateString(),
            'product_id' => $otherOrgProduct->id,
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseCount('vaccinations', 0);
        $this->assertSame(10, $otherOrgProduct->fresh()->stock_quantity);
    }

    private function createStockedProduct(int $quantity, bool $trackBatches = false): Product
    {
        return Product::create([
            'professional_id' => $this->vet->id,
            'name' => 'Vacina Antirrábica',
            'sku' => 'TEST-VAC-'.uniqid(),
            'purpose' => 'consumable',
            'controls_stock' => true,
            'track_batches' => $trackBatches,
            'stock_quantity' => $quantity,
        ]);
    }
}
