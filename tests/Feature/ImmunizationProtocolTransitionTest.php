<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Critérios de aceite da spec 13: multi-espécie (`immunization_product_species`) e
 * transição de produto entre doses (`transitions_to_product_id`, metadado informativo desta
 * fatia — não automatiza troca de protocolo do plano, ver contrato de API).
 */
class ImmunizationProtocolTransitionTest extends TestCase
{
    use RefreshDatabase;

    private User $vet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $organization = Organization::factory()->create();
        $this->vet = User::factory()->professional()->create();
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $this->vet->id,
            'role' => OrganizationMember::ROLE_OWNER,
        ]);

        Sanctum::actingAs($this->vet);
    }

    public function test_a_vaccine_can_be_marked_for_dog_and_cat(): void
    {
        $response = $this->postJson('/api/professional/immunization-products', [
            'name' => 'Antirrábica',
            'group' => 'vaccine',
            'species' => ['dog', 'cat'],
        ]);

        $response->assertStatus(201);
        $this->assertEqualsCanonicalizing(['dog', 'cat'], $response->json('data.species'));
        $this->assertDatabaseCount('immunization_product_species', 2);
    }

    public function test_a_protocol_dose_can_declare_a_transition_to_another_product(): void
    {
        $v8 = $this->postJson('/api/professional/immunization-products', [
            'name' => 'V8', 'group' => 'vaccine', 'species' => ['dog'],
        ])->json('data');

        $v10 = $this->postJson('/api/professional/immunization-products', [
            'name' => 'V10', 'group' => 'vaccine', 'species' => ['dog'],
        ])->json('data');

        $protocol = $this->postJson("/api/professional/immunization-products/{$v8['id']}/protocols", [
            'name' => 'V8 com transição para V10',
            'application_mode' => 'fixed_doses',
            'total_doses' => 3,
            'doses' => [
                ['dose_number' => 1, 'interval_days' => 45, 'anchor' => 'last_application'],
                ['dose_number' => 2, 'interval_days' => 21, 'depends_on_dose_number' => 1, 'anchor' => 'last_application'],
                [
                    'dose_number' => 3, 'interval_days' => 21, 'depends_on_dose_number' => 2,
                    'anchor' => 'last_application', 'transitions_to_product_id' => $v10['id'],
                ],
            ],
        ]);

        $protocol->assertStatus(201);
        $thirdDose = collect($protocol->json('data.doses'))->firstWhere('dose_number', 3);
        $this->assertSame($v10['id'], $thirdDose['transitions_to_product_id']);
    }
}
