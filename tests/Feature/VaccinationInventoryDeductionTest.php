<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Organization;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\User;
use App\Models\Vaccination;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/vinculo-estoque-aplicacao-clinica.md — vínculo OPCIONAL entre vacina/vermífugo e
 * `inventories`. Sem `inventory_id` (o caso normal) nunca mexe em estoque; com `inventory_id`,
 * a baixa é atômica: saldo insuficiente e lote vencido sem confirmação revertem a transação
 * inteira, nem o registro clínico nem o movimento ficam gravados.
 */
class VaccinationInventoryDeductionTest extends TestCase
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

    public function test_registering_a_vaccination_without_inventory_id_never_touches_stock(): void
    {
        $item = $this->createInventoryItem(quantity: 5);

        $response = $this->postJson("/api/pets/{$this->pet->id}/health/vaccinations", [
            'vaccine_name' => 'V10',
            'application_date' => now()->toDateString(),
        ]);

        $response->assertCreated();
        $this->assertSame(5, $item->fresh()->quantity);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_registering_a_vaccination_with_inventory_id_decrements_stock_and_logs_movement(): void
    {
        $item = $this->createInventoryItem(quantity: 1);

        $response = $this->postJson("/api/pets/{$this->pet->id}/health/vaccinations", [
            'vaccine_name' => 'V10',
            'application_date' => now()->toDateString(),
            'inventory_id' => $item->id,
        ]);

        $response->assertCreated();
        $this->assertSame(0, $item->fresh()->quantity);

        $vaccination = Vaccination::where('pet_id', $this->pet->id)->firstOrFail();
        $this->assertSame($item->id, $vaccination->inventory_id);

        $this->assertDatabaseCount('inventory_movements', 1);
        $movement = InventoryMovement::firstOrFail();
        $this->assertSame('out_vaccination', $movement->type->value);
        $this->assertSame(-1, $movement->quantity_delta);
        $this->assertSame(Vaccination::class, $movement->reference_type);
        $this->assertSame($vaccination->id, $movement->reference_id);
    }

    public function test_insufficient_stock_returns_422_and_rolls_back_everything(): void
    {
        $item = $this->createInventoryItem(quantity: 0);

        $response = $this->postJson("/api/pets/{$this->pet->id}/health/vaccinations", [
            'vaccine_name' => 'V10',
            'application_date' => now()->toDateString(),
            'inventory_id' => $item->id,
        ]);

        $response->assertStatus(422)->assertJsonPath('code', 'insufficient_stock');

        $this->assertDatabaseCount('vaccinations', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertSame(0, $item->fresh()->quantity);
    }

    public function test_expired_batch_requires_explicit_confirmation(): void
    {
        $item = $this->createInventoryItem(quantity: 3, expiryDate: now()->subDay());

        $blocked = $this->postJson("/api/pets/{$this->pet->id}/health/vaccinations", [
            'vaccine_name' => 'V10',
            'application_date' => now()->toDateString(),
            'inventory_id' => $item->id,
        ]);

        $blocked->assertStatus(422)->assertJsonPath('code', 'expired_batch');
        $this->assertDatabaseCount('vaccinations', 0);
        $this->assertSame(3, $item->fresh()->quantity);

        $confirmed = $this->postJson("/api/pets/{$this->pet->id}/health/vaccinations", [
            'vaccine_name' => 'V10',
            'application_date' => now()->toDateString(),
            'inventory_id' => $item->id,
            'confirm_expired' => true,
        ]);

        $confirmed->assertCreated();
        $this->assertSame(2, $item->fresh()->quantity);
    }

    public function test_deworming_follows_the_same_stock_rule_as_vaccination(): void
    {
        $item = $this->createInventoryItem(quantity: 1, category: 'medication');

        $response = $this->postJson("/api/pets/{$this->pet->id}/health/dewormings", [
            'product_name' => 'Drontal',
            'applied_date' => now()->toDateString(),
            'inventory_id' => $item->id,
        ]);

        $response->assertCreated();
        $this->assertSame(0, $item->fresh()->quantity);

        $movement = InventoryMovement::firstOrFail();
        $this->assertSame('out_deworming', $movement->type->value);
    }

    public function test_vet_cannot_link_stock_from_another_organizations_inventory(): void
    {
        $otherOrgItem = Inventory::create([
            'professional_id' => User::factory()->professional()->create()->id,
            'organization_id' => Organization::factory()->create()->id,
            'item_name' => 'Vacina de outra clínica',
            'category' => 'vaccine',
            'quantity' => 10,
        ]);

        $response = $this->postJson("/api/pets/{$this->pet->id}/health/vaccinations", [
            'vaccine_name' => 'V10',
            'application_date' => now()->toDateString(),
            'inventory_id' => $otherOrgItem->id,
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseCount('vaccinations', 0);
        $this->assertSame(10, $otherOrgItem->fresh()->quantity);
    }

    private function createInventoryItem(int $quantity, ?Carbon $expiryDate = null, string $category = 'vaccine'): Inventory
    {
        return Inventory::create([
            'professional_id' => $this->vet->id,
            'item_name' => 'Vacina Antirrábica',
            'category' => $category,
            'quantity' => $quantity,
            'expiry_date' => $expiryDate,
        ]);
    }
}
