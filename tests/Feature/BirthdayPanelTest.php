<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Pet;
use App\Models\ProfessionalClient;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `GET reports/birthdays` — contrato `docs/gap-simplesvet/specs/25-paineis-operacionais-spec.md`,
 * regras de negócio 3 (aniversário sem ano completo + 29/02) e 4 (contato em massa exige
 * `clients.contact.view-bulk`). Teste escrito conforme a regra do projeto: NÃO executado via
 * `artisan test`.
 */
class BirthdayPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $professional;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->professional = User::factory()->professional()->create();
        $this->client = User::factory()->tutor()->create(['birth_date' => null]);

        ProfessionalClient::create([
            'professional_id' => $this->professional->id,
            'client_id' => $this->client->id,
        ]);

        Sanctum::actingAs($this->professional);
    }

    public function test_pet_birthday_within_window_appears_with_tutor_reference(): void
    {
        Pet::factory()->create([
            'user_id' => $this->client->id,
            'name' => 'Rex',
            'birth_date' => now()->addDays(3)->subYears(2)->toDateString(),
        ]);

        $response = $this->getJson('/api/professional/reports/birthdays?'.http_build_query([
            'from' => now()->toDateString(),
            'to' => now()->addDays(7)->toDateString(),
            'scope' => 'pets',
        ]));

        $response->assertOk();
        $names = collect($response->json('data.pets'))->pluck('name');
        $this->assertContains('Rex', $names);
    }

    public function test_leap_day_birthday_appears_on_february_28_in_non_leap_year(): void
    {
        $this->travelTo(\Carbon\Carbon::create(2027, 2, 28, 12));

        Pet::factory()->create([
            'user_id' => $this->client->id,
            'name' => 'Bissexto',
            'birth_date' => '2016-02-29',
        ]);

        $response = $this->getJson('/api/professional/reports/birthdays?'.http_build_query([
            'from' => '2027-02-28',
            'to' => '2027-02-28',
            'scope' => 'pets',
        ]));

        $response->assertOk();
        $names = collect($response->json('data.pets'))->pluck('name');
        $this->assertContains('Bissexto', $names);
    }

    /**
     * O painel inteiro fica atrás de `clients.contact.view-bulk` (rota em `routes/api.php`,
     * correção do item 22): quem não tem a permissão não vê nem a lista sem contato. O papel
     * `vet_freelancer` concede a permissão, então o usuário passa a ser só `clinic_vet`.
     */
    public function test_panel_is_forbidden_without_bulk_contact_permission(): void
    {
        $this->professional->syncRoles(['clinic_vet']);

        Pet::factory()->create([
            'user_id' => $this->client->id,
            'birth_date' => now()->addDay()->subYears(1)->toDateString(),
        ]);

        $this->getJson('/api/professional/reports/birthdays?scope=pets')->assertForbidden();
    }

    public function test_contact_fields_appear_with_bulk_contact_permission(): void
    {
        Pet::factory()->create([
            'user_id' => $this->client->id,
            'birth_date' => now()->addDay()->subYears(1)->toDateString(),
        ]);

        $response = $this->getJson('/api/professional/reports/birthdays?scope=pets')->assertOk();

        $tutor = collect($response->json('data.pets'))->first()['tutor'];
        $this->assertArrayHasKey('phone', $tutor);
        $this->assertSame($this->client->email, $tutor['email']);
    }

    /**
     * Exceção pontual da organização (`organization_members.permissions`): a rota já deixava
     * entrar, mas o controller checava só o papel Spatie e escondia o contato. A permissão
     * efetiva tem de valer igual na rota, na lista e na exportação.
     */
    public function test_organization_override_shows_contact_and_allows_export(): void
    {
        OrganizationMember::factory()->for(Organization::factory()->create(), 'organization')->create([
            'user_id' => $this->professional->id,
            'role' => OrganizationRole::VETERINARIAN->value,
            'permissions' => ['clients.contact.view-bulk'],
            'is_active' => true,
        ]);
        // Criar o vínculo re-deriva os papéis pelo cargo: o papel é fixado depois, e o teste
        // confirma que a permissão vem só da exceção da organização.
        $this->professional->syncRoles(['clinic_vet']);
        $this->professional->refresh();
        $this->assertFalse($this->professional->can('clients.contact.view-bulk'));
        Pet::factory()->create([
            'user_id' => $this->client->id,
            'birth_date' => now()->addDay()->subYears(1)->toDateString(),
        ]);

        $response = $this->getJson('/api/professional/reports/birthdays?scope=pets')->assertOk();

        $tutor = collect($response->json('data.pets'))->first()['tutor'];
        $this->assertSame($this->client->email, $tutor['email']);
        $this->get('/api/professional/reports/birthdays/export?scope=pets')->assertOk();
    }
}
