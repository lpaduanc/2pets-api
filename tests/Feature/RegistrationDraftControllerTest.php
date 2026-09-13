<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Professional;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cobertura do autosave de rascunho de cadastro (`RegistrationDraftController` +
 * `RegistrationDraftService` + `*DraftRepository`), refatorado nesta sessão a partir de um
 * controller de 789 linhas sem Form Request nem teste nenhum (ver
 * `refatoracao-registration-draft-onda4-2026-09-13.md`).
 *
 * `RegistrationDraftDuplicateProtectionTest` e `DuplicateRegistrationExceptionMappingTest`
 * continuam cobrindo especificamente a proteção contra colisão de documento — não repetido
 * aqui além de um teste de fumaça por endpoint.
 */
class RegistrationDraftControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_professional_draft_persists_progressively_and_round_trips_on_load(): void
    {
        $user = User::factory()->create(['user_type' => 'vet']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/draft/professional', [
            'business_name' => 'Clínica Pata Feliz',
            'crmv' => '12345',
            'crmv_state' => 'sp',
            'services_offered' => ['consultation', 'vaccination'],
            'address' => 'Rua das Flores',
            'number' => '100',
            'neighborhood' => 'Centro',
            'city' => 'Campinas',
            'state' => 'SP',
            'zip_code' => '13000-000',
        ]);

        $response->assertOk()->assertJsonPath('saved_to_database', true);

        $this->assertDatabaseHas('professionals', [
            'user_id' => $user->id,
            'business_name' => 'Clínica Pata Feliz',
            // Normalizado para o formato canônico ANTES da gravação, ver
            // `SaveProfessionalDraftRequest::prepareForValidation()`.
            'crmv' => 'CRMV/SP 12345',
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'address' => 'Rua das Flores',
            'city' => 'Campinas',
        ]);

        $loadResponse = $this->getJson('/api/register/draft/professional');

        $loadResponse->assertOk()
            ->assertJsonPath('draft.business_name', 'Clínica Pata Feliz')
            ->assertJsonPath('draft.crmv', 'CRMV/SP 12345')
            ->assertJsonPath('draft.address', 'Rua das Flores')
            ->assertJsonPath('has_database_data', true);
    }

    public function test_professional_draft_rejects_invalid_graduation_year(): void
    {
        $user = User::factory()->create(['user_type' => 'vet']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/draft/professional', [
            'graduation_year' => 1800,
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['graduation_year' => ['Ano de formatura inválido.']]);
    }

    /**
     * Regressão do bug encontrado nesta sessão: `RegistrationDraftController::fetchCompanyData()`
     * lia `$company->company_address`/`company_phone`/`company_email` — colunas que não
     * existem em `companies` (endereço mora em `users`). Eloquent devolvia `null` em silêncio,
     * então o rascunho de empresa nunca restaurava o endereço digitado.
     */
    public function test_company_draft_persists_address_to_users_table_and_restores_it_on_load(): void
    {
        $user = User::factory()->create(['user_type' => 'company']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/draft/company', [
            'company_name' => 'Pet Corp Benefícios',
            'industry_sector' => 'technology',
            'address' => 'Avenida Central',
            'number' => '500',
            'neighborhood' => 'Jardim América',
            'city' => 'São Paulo',
            'state' => 'SP',
            'zip_code' => '01000-000',
        ]);

        $response->assertOk()->assertJsonPath('saved_to_database', true);

        // Endereço vai para `users`, nunca para `companies` (que não tem essas colunas).
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'address' => 'Avenida Central',
            'city' => 'São Paulo',
        ]);
        $this->assertDatabaseHas('companies', [
            'user_id' => $user->id,
            'company_name' => 'Pet Corp Benefícios',
        ]);

        $loadResponse = $this->getJson('/api/register/draft/company');

        $loadResponse->assertOk()
            ->assertJsonPath('draft.address', 'Avenida Central')
            ->assertJsonPath('draft.city', 'São Paulo')
            ->assertJsonPath('draft.company_name', 'Pet Corp Benefícios');
    }

    public function test_company_draft_rejects_cnpj_already_used_by_another_company(): void
    {
        Company::factory()->create(['cnpj' => '11222333000181']);

        $user = User::factory()->create(['user_type' => 'company']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/draft/company', [
            'cnpj' => '11.222.333/0001-81',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('duplicate.fields', ['cnpj'])
            ->assertJsonFragment(['cnpj' => ['Este CNPJ já está cadastrado.']]);
    }

    public function test_tutor_draft_persists_and_round_trips_on_load(): void
    {
        $user = User::factory()->create(['user_type' => 'tutor']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/draft/tutor', [
            'cpf' => '390.533.447-05',
            'gender' => 'female',
            'address' => 'Rua dos Tutores',
            'city' => 'Campinas',
        ]);

        $response->assertOk()->assertJsonPath('saved_to_database', true);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'cpf' => '39053344705',
            'gender' => 'female',
            'city' => 'Campinas',
        ]);

        $loadResponse = $this->getJson('/api/register/draft/tutor');

        $loadResponse->assertOk()
            ->assertJsonPath('draft.gender', 'female')
            ->assertJsonPath('draft.city', 'Campinas');
    }

    public function test_tutor_draft_rejects_invalid_gender(): void
    {
        $user = User::factory()->create(['user_type' => 'tutor']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/draft/tutor', [
            'gender' => 'unknown-value',
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['gender' => ['Selecione um gênero válido.']]);
    }

    public function test_deleting_draft_forgets_cache_without_touching_database_data(): void
    {
        $user = User::factory()->create(['user_type' => 'vet']);
        Professional::factory()->create(['user_id' => $user->id, 'business_name' => 'Já Cadastrado']);
        Sanctum::actingAs($user);

        Cache::put("registration_draft_professional_{$user->id}", ['business_name' => 'Rascunho em cache'], now()->addDay());

        $response = $this->deleteJson('/api/register/draft/professional');

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertNull(Cache::get("registration_draft_professional_{$user->id}"));
        $this->assertDatabaseHas('professionals', [
            'user_id' => $user->id,
            'business_name' => 'Já Cadastrado',
        ]);
    }
}
