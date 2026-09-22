<?php

namespace Tests\Feature;

use App\Models\Pet;
use App\Models\ProfessionalClient;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Regra de negócio 4 de `docs/gap-simplesvet/specs/25-paineis-operacionais-spec.md`: exportação
 * de contato em massa exige `clients.contact.view-bulk` e fica registrada em `activity_log`
 * com quantidade de linhas e filtro usado. Teste escrito conforme a regra do projeto: NÃO
 * executado via `artisan test`.
 */
class ContactExportAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_without_permission_is_forbidden(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $professional = User::factory()->professional()->create();
        $professional->revokePermissionTo('clients.contact.view-bulk');

        Sanctum::actingAs($professional);

        $this->getJson('/api/professional/reports/birthdays/export')->assertStatus(403);
    }

    public function test_export_with_permission_records_activity_log_with_row_count_and_filters(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $professional = User::factory()->professional()->create();
        $client = User::factory()->tutor()->create();
        Pet::factory()->create(['user_id' => $client->id, 'birth_date' => now()->addDay()->subYears(1)->toDateString()]);
        ProfessionalClient::create(['professional_id' => $professional->id, 'client_id' => $client->id]);

        Sanctum::actingAs($professional);

        $this->get('/api/professional/reports/birthdays/export?scope=pets')->assertOk();

        $activity = Activity::where('log_name', 'bulk-contact-export')->latest('id')->first();

        $this->assertNotNull($activity);
        $this->assertSame($professional->id, $activity->causer_id);
        $this->assertSame('birthdays', $activity->getExtraProperty('panel'));
        $this->assertSame(1, $activity->getExtraProperty('row_count'));
    }
}
