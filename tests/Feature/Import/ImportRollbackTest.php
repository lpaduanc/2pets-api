<?php

namespace Tests\Feature\Import;

use App\Enums\ImmunizationApplicationMode;
use App\Enums\ImmunizationDoseAnchor;
use App\Enums\ImmunizationGroup;
use App\Enums\Import\ImportRowStatus;
use App\Enums\Import\ImportStatus;
use App\Enums\PetImmunizationPlanStatus;
use App\Enums\StockDirection;
use App\Enums\StockMovementType;
use App\Models\Appointment;
use App\Models\DataImport;
use App\Models\ImmunizationProduct;
use App\Models\ImmunizationProtocol;
use App\Models\ImmunizationProtocolDose;
use App\Models\Pet;
use App\Models\PetImmunizationDose;
use App\Models\PetImmunizationPlan;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Vaccination;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Item 26 do backlog gap-simplesvet — rollback de `pets`/`products`/`vaccinations`
 * (`ImportRollbackService`), fechado nesta passada final. Mesmo espírito dos testes de
 * `clients` já existentes em `DataImportFlowTest`: desfazer com sucesso quando o registro
 * nunca foi tocado depois, recusar quando já foi.
 */
class ImportRollbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_rollback_removes_pet_created_by_the_import(): void
    {
        $vet = User::factory()->professional()->create();
        $tutor = User::factory()->tutor()->create();
        $pet = Pet::factory()->create(['user_id' => $tutor->id]);
        $import = $this->importRowFor($vet, 'pets', Pet::class, $pet->id);

        $response = $this->actingAs($vet)->postJson("/api/data-imports/{$import->id}/rollback");

        $response->assertOk();
        $response->assertJsonPath('rolled_back', 1);
        $this->assertNull(Pet::find($pet->id));
    }

    public function test_rollback_refuses_pet_with_appointment_created_after_import(): void
    {
        $vet = User::factory()->professional()->create();
        $tutor = User::factory()->tutor()->create();
        $pet = Pet::factory()->create(['user_id' => $tutor->id]);
        Appointment::create([
            'professional_id' => $vet->id,
            'client_id' => $tutor->id,
            'pet_id' => $pet->id,
            'appointment_date' => now(),
            'appointment_time' => now()->format('H:i'),
            'duration' => 30,
            'type' => 'consultation',
            'status' => 'scheduled',
        ]);
        $import = $this->importRowFor($vet, 'pets', Pet::class, $pet->id);

        $response = $this->actingAs($vet)->postJson("/api/data-imports/{$import->id}/rollback");

        $response->assertOk();
        $response->assertJsonPath('rolled_back', 0);
        $this->assertCount(1, $response->json('refused'));
        $this->assertNotNull(Pet::find($pet->id));
    }

    public function test_rollback_removes_product_created_by_the_import_and_its_opening_balance(): void
    {
        $vet = User::factory()->professional()->create();
        $product = Product::create([
            'professional_id' => $vet->id,
            'name' => 'Ração Premium 3kg',
            'sku' => 'SKU-'.uniqid(),
            'price' => 100.00,
            'stock_quantity' => 10,
            'controls_stock' => true,
            'is_active' => true,
        ]);
        $this->createStockMovement($product, $vet, StockMovementType::OPENING_BALANCE, StockDirection::IN, 10, 10);
        $import = $this->importRowFor($vet, 'products', Product::class, $product->id);

        $response = $this->actingAs($vet)->postJson("/api/data-imports/{$import->id}/rollback");

        $response->assertOk();
        $response->assertJsonPath('rolled_back', 1);
        $this->assertNull(Product::find($product->id));
        $this->assertSame(0, StockMovement::where('product_id', $product->id)->count());
    }

    public function test_rollback_refuses_product_with_stock_movement_beyond_opening_balance(): void
    {
        $vet = User::factory()->professional()->create();
        $product = Product::create([
            'professional_id' => $vet->id,
            'name' => 'Ração Premium 3kg',
            'sku' => 'SKU-'.uniqid(),
            'price' => 100.00,
            'stock_quantity' => 8,
            'controls_stock' => true,
            'is_active' => true,
        ]);
        $this->createStockMovement($product, $vet, StockMovementType::OPENING_BALANCE, StockDirection::IN, 10, 10);
        $this->createStockMovement($product, $vet, StockMovementType::SALE_OUT, StockDirection::OUT, 2, 8);
        $import = $this->importRowFor($vet, 'products', Product::class, $product->id);

        $response = $this->actingAs($vet)->postJson("/api/data-imports/{$import->id}/rollback");

        $response->assertOk();
        $response->assertJsonPath('rolled_back', 0);
        $this->assertCount(1, $response->json('refused'));
        $this->assertNotNull(Product::find($product->id));
    }

    public function test_rollback_removes_vaccination_created_by_the_import(): void
    {
        $vet = User::factory()->professional()->create();
        $tutor = User::factory()->tutor()->create();
        $pet = Pet::factory()->create(['user_id' => $tutor->id]);
        $vaccination = Vaccination::create([
            'pet_id' => $pet->id,
            'professional_id' => null,
            'vaccine_name' => 'V10',
            'application_date' => now()->subMonth(),
        ]);
        $import = $this->importRowFor($vet, 'vaccinations', Vaccination::class, $vaccination->id);

        $response = $this->actingAs($vet)->postJson("/api/data-imports/{$import->id}/rollback");

        $response->assertOk();
        $response->assertJsonPath('rolled_back', 1);
        $this->assertNull(Vaccination::find($vaccination->id));
    }

    public function test_rollback_refuses_vaccination_linked_to_an_immunization_plan_dose(): void
    {
        $vet = User::factory()->professional()->create();
        $tutor = User::factory()->tutor()->create();
        $pet = Pet::factory()->create(['user_id' => $tutor->id]);
        $vaccination = Vaccination::create([
            'pet_id' => $pet->id,
            'professional_id' => null,
            'vaccine_name' => 'V10',
            'application_date' => now()->subMonth(),
        ]);
        $dose = $this->planDoseFor($pet, $vaccination);
        $import = $this->importRowFor($vet, 'vaccinations', Vaccination::class, $vaccination->id);

        $response = $this->actingAs($vet)->postJson("/api/data-imports/{$import->id}/rollback");

        $response->assertOk();
        $response->assertJsonPath('rolled_back', 0);
        $this->assertCount(1, $response->json('refused'));
        $this->assertNotNull(Vaccination::find($vaccination->id));
        $this->assertNotNull(PetImmunizationDose::find($dose->id));
    }

    private function createStockMovement(
        Product $product,
        User $professional,
        StockMovementType $type,
        StockDirection $direction,
        int $quantity,
        int $balanceAfter,
    ): StockMovement {
        return StockMovement::create([
            'product_id' => $product->id,
            'professional_id' => $professional->id,
            'type' => $type->value,
            'direction' => $direction->value,
            'quantity' => $quantity,
            'unit_cost' => 50,
            'balance_after' => $balanceAfter,
            'occurred_at' => now(),
        ]);
    }

    private function importRowFor(User $professional, string $entity, string $recordType, int $recordId): DataImport
    {
        $import = DataImport::create([
            'professional_id' => $professional->id,
            'user_id' => $professional->id,
            'entity' => $entity,
            'file_path' => 'data-imports/fake.csv',
            'status' => ImportStatus::COMPLETED->value,
            'batch_uuid' => (string) Str::uuid(),
        ]);

        $import->rows()->create([
            'row_number' => 1,
            'raw' => [],
            'status' => ImportRowStatus::IMPORTED->value,
            'created_record_type' => $recordType,
            'created_record_id' => $recordId,
        ]);

        return $import;
    }

    private function planDoseFor(Pet $pet, Vaccination $vaccination): PetImmunizationDose
    {
        $product = ImmunizationProduct::create([
            'name' => 'V10',
            'group' => ImmunizationGroup::VACCINE->value,
        ]);
        $protocol = ImmunizationProtocol::create([
            'immunization_product_id' => $product->id,
            'name' => 'Protocolo padrão',
            'application_mode' => ImmunizationApplicationMode::FIXED_DOSES->value,
            'total_doses' => 1,
        ]);
        $protocolDose = ImmunizationProtocolDose::create([
            'protocol_id' => $protocol->id,
            'dose_number' => 1,
            'anchor' => ImmunizationDoseAnchor::FIRST_APPLICATION->value,
        ]);
        $plan = PetImmunizationPlan::create([
            'pet_id' => $pet->id,
            'protocol_id' => $protocol->id,
            'started_at' => now()->subMonth(),
            'status' => PetImmunizationPlanStatus::ACTIVE->value,
        ]);

        return PetImmunizationDose::create([
            'plan_id' => $plan->id,
            'protocol_dose_id' => $protocolDose->id,
            'scheduled_for' => now()->subMonth(),
            'applied_at' => now()->subMonth(),
            'vaccination_id' => $vaccination->id,
        ]);
    }
}
