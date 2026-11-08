<?php

namespace Tests\Feature;

use App\Models\Pet;
use App\Models\ProfessionalClient;
use App\Models\User;
use App\Models\Vaccination;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `GET reports/immunization` — contrato `docs/gap-simplesvet/specs/25-paineis-operacionais-spec.md`.
 * Reusa `Vaccination::scopeLatestPerType()`; "vencida" nunca conta uma dose já superada.
 * Teste escrito conforme a regra do projeto: NÃO executado via `artisan test`.
 */
class OperationalImmunizationPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $professional;

    private Pet $pet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->professional = User::factory()->professional()->create();
        $client = User::factory()->tutor()->create();
        $this->pet = Pet::factory()->create(['user_id' => $client->id]);

        ProfessionalClient::create(['professional_id' => $this->professional->id, 'client_id' => $client->id]);

        Sanctum::actingAs($this->professional);
    }

    public function test_overdue_vaccine_counts_as_overdue_not_applied(): void
    {
        Vaccination::create([
            'pet_id' => $this->pet->id,
            'professional_id' => $this->professional->id,
            'vaccine_name' => 'V10',
            'application_date' => now()->subYear(),
            'next_dose_date' => now()->subDays(10),
        ]);

        $response = $this->getJson('/api/professional/reports/immunization')->assertOk();

        $response->assertJsonPath('data.summary.total', 1);
        $response->assertJsonPath('data.summary.overdue', 1);
        $response->assertJsonPath('data.summary.applied', 0);
    }

    public function test_newer_dose_supersedes_older_dose_of_same_vaccine(): void
    {
        Vaccination::create([
            'pet_id' => $this->pet->id,
            'professional_id' => $this->professional->id,
            'vaccine_name' => 'V10',
            'application_date' => now()->subYears(2),
            'next_dose_date' => now()->subYears(1),
        ]);

        Vaccination::create([
            'pet_id' => $this->pet->id,
            'professional_id' => $this->professional->id,
            'vaccine_name' => 'V10',
            'application_date' => now()->subMonth(),
            'next_dose_date' => null,
        ]);

        $response = $this->getJson('/api/professional/reports/immunization')->assertOk();

        $response->assertJsonPath('data.summary.total', 1);
        $response->assertJsonPath('data.summary.applied', 1);
    }

    /**
     * O painel inteiro fica atrás de `clients.contact.view-bulk` (rota em `routes/api.php`,
     * correção do item 22). O papel `vet_freelancer` concede a permissão, então o usuário passa
     * a ser só `clinic_vet`, que não a tem.
     */
    public function test_panel_is_forbidden_without_bulk_contact_permission(): void
    {
        $this->professional->syncRoles(['clinic_vet']);

        Vaccination::create([
            'pet_id' => $this->pet->id,
            'professional_id' => $this->professional->id,
            'vaccine_name' => 'V10',
            'application_date' => now(),
            'next_dose_date' => now()->addMonth(),
        ]);

        $this->getJson('/api/professional/reports/immunization')->assertForbidden();
    }
}
