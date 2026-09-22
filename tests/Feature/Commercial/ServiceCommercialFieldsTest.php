<?php

namespace Tests\Feature\Commercial;

use App\Models\Organization;
use App\Models\ProductGroup;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Achado do frontend-specialist ao integrar `ServiceCommercialFields.vue`: os campos comerciais
 * do doc 08 (`code`, `product_group_id`, `commission_percent`, `municipal_service_code`,
 * `lc116_code`, `show_in_price_list`, `allow_price_override`) não estavam nas regras do
 * `Validator::make` inline de `ServiceController`, então `validated()` os descartava
 * silenciosamente — a tela salvava sem persistir nada. Corrigido extraindo a validação para
 * `StoreServiceRequest`/`UpdateServiceRequest`.
 */
class ServiceCommercialFieldsTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildClinic();
    }

    public function test_creating_a_service_persists_the_commercial_fields(): void
    {
        $group = ProductGroup::create([
            'organization_id' => $this->clinic->id,
            'name' => 'Banho e Tosa',
        ]);

        $response = $this->actingAs($this->groomer, 'sanctum')->postJson('/api/professional/services', [
            'name' => 'Banho porte médio',
            'category' => 'grooming',
            'duration' => 60,
            'price' => 60.0,
            'code' => 'S010',
            'product_group_id' => $group->id,
            'commission_percent' => 30,
            'municipal_service_code' => '0601',
            'lc116_code' => '0601',
            'show_in_price_list' => false,
            'allow_price_override' => false,
        ])->assertCreated();

        $service = Service::findOrFail($response->json('service.id'));

        $this->assertSame('S010', $service->code);
        $this->assertSame($group->id, $service->product_group_id);
        $this->assertSame('30.0000', $service->commission_percent);
        $this->assertSame('0601', $service->municipal_service_code);
        $this->assertSame('0601', $service->lc116_code);
        $this->assertFalse($service->show_in_price_list);
        $this->assertFalse($service->allow_price_override);
    }

    public function test_updating_a_service_persists_the_commercial_fields(): void
    {
        $service = $this->makeService();

        $this->actingAs($this->groomer, 'sanctum')
            ->putJson("/api/professional/services/{$service->id}", ['code' => 'S099', 'commission_percent' => 15])
            ->assertOk();

        $service->refresh();
        $this->assertSame('S099', $service->code);
        $this->assertSame('15.0000', $service->commission_percent);
    }

    public function test_a_product_group_from_another_clinic_is_rejected(): void
    {
        $otherClinic = Organization::factory()->create();
        $foreignGroup = ProductGroup::create([
            'organization_id' => $otherClinic->id,
            'name' => 'Grupo alheio',
        ]);

        $this->actingAs($this->groomer, 'sanctum')->postJson('/api/professional/services', [
            'name' => 'Banho porte médio', 'category' => 'grooming', 'duration' => 60,
            'price' => 60.0, 'product_group_id' => $foreignGroup->id,
        ])->assertStatus(422);
    }
}
