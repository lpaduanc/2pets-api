<?php

namespace Tests\Feature;

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

    public function test_contact_fields_are_hidden_without_bulk_contact_permission(): void
    {
        $this->professional->revokePermissionTo('clients.contact.view-bulk');

        Pet::factory()->create([
            'user_id' => $this->client->id,
            'birth_date' => now()->addDay()->subYears(1)->toDateString(),
        ]);

        $response = $this->getJson('/api/professional/reports/birthdays?scope=pets')->assertOk();

        $tutor = collect($response->json('data.pets'))->first()['tutor'];
        $this->assertArrayNotHasKey('phone', $tutor);
        $this->assertArrayNotHasKey('email', $tutor);
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
}
