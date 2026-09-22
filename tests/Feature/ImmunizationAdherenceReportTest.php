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

/** `GET reports/immunization-adherence` — contrato spec 13. */
class ImmunizationAdherenceReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_total_applied_and_pending_for_a_period_and_organization(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $organization = Organization::factory()->create();
        $vet = User::factory()->professional()->create();
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $vet->id,
            'role' => OrganizationMember::ROLE_OWNER,
        ]);

        $tutor = User::factory()->tutor()->create();
        $pet = Pet::factory()->create(['user_id' => $tutor->id, 'species' => 'Dog']);
        PetVetAccess::create([
            'pet_id' => $pet->id,
            'veterinarian_id' => $vet->id,
            'granted_by' => $tutor->id,
            'access_level' => VetAccessLevel::WRITE,
            'status' => PetVetAccess::STATUS_ACCEPTED,
            'is_active' => true,
            'granted_at' => now(),
            'responded_at' => now(),
        ]);

        Sanctum::actingAs($vet);

        $product = $this->postJson('/api/professional/immunization-products', [
            'name' => 'V10', 'group' => 'vaccine', 'species' => ['dog'],
        ])->json('data');

        $protocol = $this->postJson("/api/professional/immunization-products/{$product['id']}/protocols", [
            'name' => 'Protocolo simples',
            'application_mode' => 'fixed_doses',
            'total_doses' => 2,
            'doses' => [
                ['dose_number' => 1, 'interval_days' => 0, 'anchor' => 'last_application'],
                ['dose_number' => 2, 'interval_days' => 21, 'depends_on_dose_number' => 1, 'anchor' => 'last_application'],
            ],
        ])->json('data');

        $plan = $this->postJson("/api/pets/{$pet->id}/immunization-plans", [
            'protocol_id' => $protocol['id'],
            'started_at' => now()->toDateString(),
        ])->json('data');

        $firstDoseId = collect($plan['doses'])->firstWhere('dose_number', 1)['id'];
        $this->postJson("/api/pets/{$pet->id}/immunization-plans/{$plan['id']}/doses/{$firstDoseId}/apply")
            ->assertOk();

        $report = $this->getJson('/api/professional/reports/immunization-adherence?'.http_build_query([
            'from' => now()->subMonth()->toDateString(),
            'to' => now()->addMonths(2)->toDateString(),
            'organization_id' => $organization->id,
        ]));

        $report->assertOk();
        $report->assertJson([
            'data' => [
                'total' => 2,
                'applied' => 1,
                'pending' => 1,
                'adherence_percentage' => 50.0,
            ],
        ]);
    }
}
