<?php

namespace Tests\Feature;

use App\Models\Pet;
use App\Models\User;
use App\Models\Vaccination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression test for the "vaccine history is append-only" bug: a superseded
 * dose (already replaced by a newer one of the same type) must never count as
 * overdue, in the dashboard alerts, the health score, or the health-summary
 * roll-up. All three read paths share `Vaccination::scopeLatestPerType`.
 */
class VaccinationOverdueRegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $tutor;

    private User $veterinarian;

    private Pet $pet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tutor = User::factory()->tutor()->create();
        $this->veterinarian = User::factory()->veterinarian()->create();
        $this->pet = Pet::factory()->create(['user_id' => $this->tutor->id, 'name' => 'Thor']);
    }

    public function test_dashboard_does_not_alert_on_a_superseded_dose_when_the_latest_is_up_to_date(): void
    {
        $this->applyDose('V10', now()->subYear(), now()->subMonths(2));
        $this->applyDose('V10', now()->subDays(10), now()->addMonths(10));
        $this->applyDose('Antirrábica', now()->subYear(), now()->subMonths(2));
        $this->applyDose('Antirrábica', now()->subDays(10), now()->addMonths(10));

        Sanctum::actingAs($this->tutor);

        $response = $this->getJson('/api/dashboard/stats')->assertOk();

        $overdueAlerts = collect($response->json('data.healthAlerts'))
            ->where('type', 'vaccine_overdue');

        $this->assertCount(0, $overdueAlerts, 'a superseded dose must not raise an overdue alert');
        $this->assertSame(100, $response->json('data.stats.healthScore'));
    }

    public function test_dashboard_still_alerts_when_the_only_dose_of_a_type_is_genuinely_overdue(): void
    {
        $this->applyDose('V10', now()->subYear(), now()->subMonths(2));

        Sanctum::actingAs($this->tutor);

        $response = $this->getJson('/api/dashboard/stats')->assertOk();

        $overdueAlerts = collect($response->json('data.healthAlerts'))
            ->where('type', 'vaccine_overdue');

        $this->assertCount(1, $overdueAlerts, 'a genuinely overdue single dose must still alert');
        $this->assertSame(0, $response->json('data.stats.healthScore'));
    }

    public function test_health_summary_does_not_report_a_superseded_dose_as_overdue(): void
    {
        $this->applyDose('V10', now()->subYear(), now()->subMonths(2));
        $this->applyDose('V10', now()->subDays(10), now()->addMonths(10));

        Sanctum::actingAs($this->tutor);

        $response = $this->getJson('/api/pets/health-summary')->assertOk();

        $response->assertJsonPath('meta.overdue_count', 0)
            ->assertJsonPath('data.0.overdue_count', 0)
            ->assertJsonCount(0, 'data.0.events');
    }

    private function applyDose(string $vaccineName, \Carbon\Carbon $applicationDate, \Carbon\Carbon $nextDoseDate): Vaccination
    {
        return Vaccination::create([
            'pet_id' => $this->pet->id,
            'professional_id' => $this->veterinarian->id,
            'vaccine_name' => $vaccineName,
            'application_date' => $applicationDate->toDateString(),
            'next_dose_date' => $nextDoseDate->toDateString(),
        ]);
    }
}
