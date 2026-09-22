<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
use App\Models\Inventory;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Motor de agendamento do grafo de doses — contrato
 * docs/gap-simplesvet/specs/13-protocolos-vacinais-spec.md, regras 3/5/6/7.
 *
 * Escrito conforme a regra do projeto: teste escrito, comparar execução com o baseline
 * antes de reportar sucesso.
 */
class ImmunizationProtocolGraphSchedulingTest extends TestCase
{
    use RefreshDatabase;

    private User $vet;

    private User $tutor;

    private Pet $pet;

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

        $this->tutor = User::factory()->tutor()->create();
        $this->pet = Pet::factory()->create(['user_id' => $this->tutor->id, 'species' => 'Dog']);

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

    public function test_starting_a_plan_schedules_every_dose_from_the_dependency_graph(): void
    {
        $protocol = $this->createThreeDoseProtocol();

        $response = $this->postJson("/api/pets/{$this->pet->id}/immunization-plans", [
            'protocol_id' => $protocol,
            'started_at' => '2026-01-01',
        ]);

        $response->assertStatus(201);
        $doses = collect($response->json('data.doses'))->sortBy('dose_number')->values();

        $this->assertCount(3, $doses);
        $this->assertSame('2026-02-15', $doses[0]['scheduled_for']); // +45 dias
        $this->assertSame('2026-03-08', $doses[1]['scheduled_for']); // +21 dias da 1ª
        $this->assertSame('2026-03-29', $doses[2]['scheduled_for']); // +21 dias da 2ª
    }

    public function test_applying_a_dose_late_reschedules_dependents_from_the_real_application_date(): void
    {
        $protocol = $this->createThreeDoseProtocol();
        $plan = $this->postJson("/api/pets/{$this->pet->id}/immunization-plans", [
            'protocol_id' => $protocol,
            'started_at' => '2026-01-01',
        ])->json('data');

        $doses = collect($plan['doses'])->sortBy('dose_number')->values();
        $secondDoseId = $doses[1]['id'];

        // 2ª dose prevista para 2026-03-08, aplicada com 5 dias de atraso.
        $applyResponse = $this->postJson(
            "/api/pets/{$this->pet->id}/immunization-plans/{$plan['id']}/doses/{$secondDoseId}/apply",
            ['applied_at' => '2026-03-13']
        );
        $applyResponse->assertOk();

        $refreshed = $this->getJson("/api/pets/{$this->pet->id}/immunization-plans/{$plan['id']}")
            ->json('data.doses');
        $thirdDose = collect($refreshed)->firstWhere('dose_number', 3);

        $this->assertSame('2026-04-03', $thirdDose['scheduled_for']); // 2026-03-13 + 21 dias
    }

    public function test_overdue_dose_is_derived_never_persisted_as_a_column(): void
    {
        $protocol = $this->createThreeDoseProtocol();
        $plan = $this->postJson("/api/pets/{$this->pet->id}/immunization-plans", [
            'protocol_id' => $protocol,
            'started_at' => now()->subYear()->toDateString(),
        ])->json('data');

        $schedule = $this->getJson("/api/pets/{$this->pet->id}/immunization-schedule")->json('data');

        $this->assertNotEmpty($schedule);
        $this->assertSame('overdue', $schedule[0]['status']);
        $this->assertDatabaseMissing('pet_immunization_doses', ['id' => $schedule[0]['id'], 'skipped' => true]);
    }

    /** Regra de negócio 7: registrar aplicação avulsa nunca exige um plano. */
    public function test_registering_a_standalone_application_without_any_plan_is_still_accepted(): void
    {
        $response = $this->postJson("/api/pets/{$this->pet->id}/health/vaccinations", [
            'vaccine_name' => 'V10 avulsa',
            'application_date' => now()->toDateString(),
        ]);

        $response->assertStatus(201);
    }

    /** Reaproveita a trava de saldo já implementada em `ClinicalStockDeductionService`. */
    public function test_applying_a_dose_linked_to_an_out_of_stock_inventory_item_returns_422(): void
    {
        $inventory = Inventory::create([
            'professional_id' => $this->vet->id,
            'item_name' => 'V10 frasco',
            'category' => 'vaccine',
            'quantity' => 0,
        ]);

        $protocol = $this->createThreeDoseProtocol();
        $plan = $this->postJson("/api/pets/{$this->pet->id}/immunization-plans", [
            'protocol_id' => $protocol,
            'started_at' => now()->toDateString(),
        ])->json('data');

        $firstDoseId = collect($plan['doses'])->firstWhere('dose_number', 1)['id'];

        $response = $this->postJson(
            "/api/pets/{$this->pet->id}/immunization-plans/{$plan['id']}/doses/{$firstDoseId}/apply",
            ['inventory_id' => $inventory->id]
        );

        $response->assertStatus(422);
        $this->assertDatabaseHas('pet_immunization_doses', ['id' => $firstDoseId, 'applied_at' => null]);
    }

    private function createThreeDoseProtocol(): int
    {
        $product = $this->postJson('/api/professional/immunization-products', [
            'name' => 'V10',
            'group' => 'vaccine',
            'species' => ['dog', 'cat'],
        ])->json('data');

        $protocol = $this->postJson("/api/professional/immunization-products/{$product['id']}/protocols", [
            'name' => 'Protocolo V10 filhote',
            'application_mode' => 'fixed_doses',
            'total_doses' => 3,
            'doses' => [
                ['dose_number' => 1, 'interval_days' => 45, 'anchor' => 'last_application'],
                ['dose_number' => 2, 'interval_days' => 21, 'depends_on_dose_number' => 1, 'anchor' => 'last_application'],
                ['dose_number' => 3, 'interval_days' => 21, 'depends_on_dose_number' => 2, 'anchor' => 'last_application'],
            ],
        ])->json('data');

        return $protocol['id'];
    }
}
