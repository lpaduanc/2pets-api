<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
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
 * `anchor = first_application` preserva a grade original mesmo com atraso na dose-mãe —
 * contrato spec 13, regra de negócio 4/5, critério de aceite: "o mesmo cenário acima, com
 * anchor = first_application, mantém a grade original".
 */
class ImmunizationProtocolAnchorTest extends TestCase
{
    use RefreshDatabase;

    private User $vet;

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

        $tutor = User::factory()->tutor()->create();
        $this->pet = Pet::factory()->create(['user_id' => $tutor->id, 'species' => 'Dog']);

        PetVetAccess::create([
            'pet_id' => $this->pet->id,
            'veterinarian_id' => $this->vet->id,
            'granted_by' => $tutor->id,
            'access_level' => VetAccessLevel::WRITE,
            'status' => PetVetAccess::STATUS_ACCEPTED,
            'is_active' => true,
            'granted_at' => now(),
            'responded_at' => now(),
        ]);

        Sanctum::actingAs($this->vet);
    }

    public function test_first_application_anchor_keeps_the_original_schedule_after_a_late_dose(): void
    {
        $product = $this->postJson('/api/professional/immunization-products', [
            'name' => 'V10',
            'group' => 'vaccine',
            'species' => ['dog'],
        ])->json('data');

        $protocol = $this->postJson("/api/professional/immunization-products/{$product['id']}/protocols", [
            'name' => 'Protocolo rígido',
            'application_mode' => 'fixed_doses',
            'total_doses' => 2,
            'doses' => [
                ['dose_number' => 1, 'interval_days' => 45, 'anchor' => 'last_application'],
                ['dose_number' => 2, 'interval_days' => 21, 'depends_on_dose_number' => 1, 'anchor' => 'first_application'],
            ],
        ])->json('data');

        $plan = $this->postJson("/api/pets/{$this->pet->id}/immunization-plans", [
            'protocol_id' => $protocol['id'],
            'started_at' => '2026-01-01',
        ])->json('data');

        $doses = collect($plan['doses'])->sortBy('dose_number')->values();
        $originalSecondDoseSchedule = $doses[1]['scheduled_for'];
        $firstDoseId = $doses[0]['id'];

        // 1ª dose prevista para 2026-02-15, aplicada com 10 dias de atraso.
        $this->postJson(
            "/api/pets/{$this->pet->id}/immunization-plans/{$plan['id']}/doses/{$firstDoseId}/apply",
            ['applied_at' => '2026-02-25']
        )->assertOk();

        $refreshed = $this->getJson("/api/pets/{$this->pet->id}/immunization-plans/{$plan['id']}")
            ->json('data.doses');
        $secondDose = collect($refreshed)->firstWhere('dose_number', 2);

        $this->assertSame($originalSecondDoseSchedule, $secondDose['scheduled_for']);
        $this->assertSame('2026-03-08', $secondDose['scheduled_for']);
    }
}
