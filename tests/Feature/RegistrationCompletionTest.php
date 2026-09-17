<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Mail\OrganizationInvitationMail;
use App\Models\Company;
use App\Models\Organization;
use App\Models\Professional;
use App\Models\User;
use App\Services\Registration\RegistrationCompletionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Cobre os quatro fluxos de `RegistrationCompletionController` depois da extração das
 * validações inline para Form Requests (`app/Http/Requests/Registration/`) e da regra de
 * CPF/CRMV para Rule objects (`app/Rules/`). Geocodificação é sempre fakeada: o teste não
 * depende de rede e o resultado (coordenadas nulas) não deve quebrar o cadastro, já que a
 * geocodificação é best-effort.
 *
 * A partir de `BusinessOrganizationRegistrar`, o fluxo de negócio também cria `Organization` +
 * `OrganizationMember` e reconcilia papel Spatie (`UserRoleReconciler`) — por isso os papéis
 * precisam existir (`RolesAndPermissionsSeeder`) e e-mail de convite ao RT terceiro é fakeado.
 */
class RegistrationCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            'maps.googleapis.com/*' => Http::response(['status' => 'ZERO_RESULTS', 'results' => []]),
        ]);
        Mail::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_tutor_completes_registration_successfully(): void
    {
        $user = User::factory()->create(['user_type' => 'tutor', 'cpf' => null]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-tutor', [
            'cpf' => '390.533.447-05',
            'birth_date' => '1990-01-01',
            'gender' => 'male',
            'address' => 'Rua das Flores',
            'number' => '100',
            'neighborhood' => 'Centro',
            'city' => 'São Paulo',
            'state' => 'SP',
            'zip_code' => '01000-000',
        ]);

        $response->assertOk()->assertJsonPath('message', 'Profile completed successfully!');

        $this->assertSame('39053344705', $user->fresh()->cpf);
        $this->assertTrue((bool) $user->fresh()->profile_completed);
    }

    public function test_tutor_registration_rejects_invalid_cpf(): void
    {
        $user = User::factory()->create(['user_type' => 'tutor', 'cpf' => null]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-tutor', [
            'cpf' => '111.111.111-11',
            'birth_date' => '1990-01-01',
            'address' => 'Rua das Flores',
            'number' => '100',
            'neighborhood' => 'Centro',
            'city' => 'São Paulo',
            'state' => 'SP',
            'zip_code' => '01000-000',
        ]);

        // Formato único de erro (padrão Laravel), não mais o corpo à mão do controller legado.
        $response->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['cpf']])
            ->assertJsonFragment(['cpf' => ['CPF inválido']]);
    }

    /**
     * Onda 1 do plano de correção do cadastro (2026-09-13): `Rule::unique` não existia em
     * nenhum dos 4 Form Requests de conclusão — duplicata de CPF passava na validação e
     * estourava `QueryException` 23505 crua no INSERT.
     */
    public function test_tutor_registration_rejects_duplicate_cpf(): void
    {
        User::factory()->create(['cpf' => '39053344705']);

        $user = User::factory()->create(['user_type' => 'tutor', 'cpf' => null]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-tutor', [
            'cpf' => '390.533.447-05',
            'birth_date' => '1990-01-01',
            'address' => 'Rua das Flores',
            'number' => '100',
            'neighborhood' => 'Centro',
            'city' => 'São Paulo',
            'state' => 'SP',
            'zip_code' => '01000-000',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('duplicate.fields', ['cpf'])
            ->assertJsonPath('duplicate.action', 'login')
            ->assertJsonFragment(['cpf' => ['Este CPF já está cadastrado.']]);

        $this->assertFalse((bool) $user->fresh()->profile_completed);
    }

    /**
     * O próprio dono do CPF completando (ou re-completando) o cadastro nunca pode ser
     * barrado pela própria unicidade — `Rule::unique(...)->ignore($this->user()->id)`.
     */
    public function test_tutor_can_complete_registration_again_with_the_same_cpf(): void
    {
        $user = User::factory()->create(['user_type' => 'tutor', 'cpf' => '39053344705']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-tutor', [
            'cpf' => '390.533.447-05',
            'birth_date' => '1990-01-01',
            'address' => 'Rua das Flores',
            'number' => '100',
            'neighborhood' => 'Centro',
            'city' => 'São Paulo',
            'state' => 'SP',
            'zip_code' => '01000-000',
        ]);

        $response->assertOk();
    }

    /**
     * Caminho manual de reivindicação — contrato
     * `docs/atendimento-veterinario/07-contrato-agendamento-pet-novo.md` §6/§7.1.
     *
     * Quem se autocadastra e digita um CPF que já pertence a uma conta NÃO reivindicada
     * (nascida do fluxo de paciente novo) precisa continuar aquele cadastro — nunca ver "CPF já
     * cadastrado", e a conta nova (casca) nunca deve sobreviver com histórico próprio.
     */
    public function test_self_registered_shell_merges_into_unclaimed_account_with_same_cpf(): void
    {
        $unclaimed = User::create([
            'name' => 'Fernanda Souza',
            'cpf' => '39053344705',
            'email' => null,
            'password' => null,
            'user_type' => 'tutor',
            'role' => 'tutor',
            'registration_status' => 'pending',
            'profile_completed' => false,
        ]);
        $pet = \App\Models\Pet::factory()->create(['user_id' => $unclaimed->id, 'name' => 'Mia']);

        $shell = User::factory()->create([
            'user_type' => 'tutor',
            'cpf' => null,
            'email' => 'fernanda.self@exemplo.com',
        ]);
        Sanctum::actingAs($shell);

        $response = $this->postJson('/api/register/complete-tutor', [
            'cpf' => '390.533.447-05',
            'birth_date' => '1990-01-01',
            'address' => 'Rua das Flores',
            'number' => '100',
            'neighborhood' => 'Centro',
            'city' => 'São Paulo',
            'state' => 'SP',
            'zip_code' => '01000-000',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.id', $unclaimed->id)
            ->assertJsonPath('user.email', 'fernanda.self@exemplo.com');
        $this->assertNotNull($response->json('access_token'));

        $merged = $unclaimed->fresh();
        $this->assertNotNull($merged->password);
        $this->assertSame('approved', $merged->registration_status);
        $this->assertTrue((bool) $merged->profile_completed);

        $this->assertSoftDeleted('users', ['id' => $shell->id]);
        $this->assertSame($unclaimed->id, $pet->fresh()->user_id);
        $this->assertSame(1, User::where('cpf', '39053344705')->count());
    }

    /**
     * Sem colisão, `access_token` continua ausente/null — o contrato exige o campo sempre
     * presente na resposta, mas só preenchido quando a fusão realmente acontece.
     */
    public function test_complete_tutor_response_has_null_access_token_when_no_claim_happens(): void
    {
        $user = User::factory()->create(['user_type' => 'tutor', 'cpf' => null]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-tutor', [
            'cpf' => '390.533.447-05',
            'birth_date' => '1990-01-01',
            'address' => 'Rua das Flores',
            'number' => '100',
            'neighborhood' => 'Centro',
            'city' => 'São Paulo',
            'state' => 'SP',
            'zip_code' => '01000-000',
        ]);

        $response->assertOk()->assertJsonPath('access_token', null);
    }

    public function test_vet_completes_registration_successfully(): void
    {
        $user = User::factory()->create(['user_type' => 'vet', 'cpf' => null]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-professional', $this->validVetPayload());

        $response->assertOk()->assertJsonPath('message', 'Vet profile completed successfully!');

        $this->assertDatabaseHas('professionals', [
            'user_id' => $user->id,
            'professional_type' => 'vet',
            'crmv_state' => 'SP',
        ]);
    }

    /**
     * Veterinário volante é sempre pessoa física — nunca ganha `Organization`
     * (`BusinessOrganizationRegistrar` só é chamado para `ProfessionalType` presente em
     * `OrganizationType`, e `vet` deliberadamente não está lá).
     */
    public function test_vet_registration_never_creates_organization(): void
    {
        $user = User::factory()->create(['user_type' => 'vet', 'cpf' => null]);
        Sanctum::actingAs($user);

        $this->postJson('/api/register/complete-professional', $this->validVetPayload())->assertOk();

        $this->assertDatabaseCount('organizations', 0);
        $this->assertDatabaseCount('organization_members', 0);
    }

    public function test_vet_registration_rejects_invalid_cpf(): void
    {
        $user = User::factory()->create(['user_type' => 'vet', 'cpf' => null]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-professional', [
            ...$this->validVetPayload(),
            'cpf' => '111.111.111-11',
        ]);

        $response->assertStatus(422)->assertJsonFragment(['cpf' => ['CPF inválido']]);
        $this->assertDatabaseMissing('professionals', ['user_id' => $user->id]);
    }

    public function test_vet_registration_rejects_invalid_crmv(): void
    {
        $user = User::factory()->create(['user_type' => 'vet', 'cpf' => null]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-professional', [
            ...$this->validVetPayload(),
            'crmv' => 'abc',
        ]);

        $response->assertStatus(422)->assertJsonFragment(['crmv' => ['CRMV inválido']]);
        $this->assertDatabaseMissing('professionals', ['user_id' => $user->id]);
    }

    public function test_vet_registration_rejects_duplicate_crmv(): void
    {
        Professional::factory()->veterinarian()->create([
            'crmv' => 'CRMV/SP 99999',
            'crmv_state' => 'SP',
        ]);

        $user = User::factory()->create(['user_type' => 'vet', 'cpf' => null]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-professional', [
            ...$this->validVetPayload(),
            'crmv' => '99999',
            'crmv_state' => 'SP',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('duplicate.fields', ['crmv'])
            ->assertJsonPath('duplicate.action', 'login')
            ->assertJsonFragment(['crmv' => ['Este CRMV já está cadastrado.']]);

        $this->assertDatabaseMissing('professionals', ['user_id' => $user->id]);
    }

    /**
     * `completeVet` grava `users` + `professionals` sem `DB::transaction()` antes desta
     * onda — uma colisão detectada só pelo banco (nunca pelo `Rule::unique`, ex.: race
     * condition) deixava o `User` com `profile_completed=true` e nenhum `Professional`
     * correspondente. Chamado direto no service para simular a colisão sobrevivendo à
     * validação do Form Request.
     */
    public function test_completing_vet_registration_rolls_back_user_update_when_crmv_collides_in_database(): void
    {
        Professional::factory()->veterinarian()->create([
            'crmv' => 'CRMV/SP 55555',
            'crmv_state' => 'SP',
        ]);

        $user = User::factory()->create(['user_type' => 'vet', 'cpf' => null, 'profile_completed' => false]);

        $threw = false;

        try {
            app(RegistrationCompletionService::class)->completeVet($user, [
                ...$this->validVetPayload(),
                'crmv' => '55555',
                'crmv_state' => 'SP',
            ]);
        } catch (QueryException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Esperava QueryException por colisão de CRMV no banco.');
        $this->assertFalse((bool) $user->fresh()->profile_completed);
        $this->assertDatabaseMissing('professionals', ['user_id' => $user->id]);
    }

    public function test_generic_professional_completes_registration_without_technical_responsible(): void
    {
        $user = User::factory()->create(['user_type' => 'petshop']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-professional', $this->validGenericProfessionalPayload());

        $response->assertOk()->assertJsonPath('message', 'Professional profile completed successfully!');

        $this->assertDatabaseHas('professionals', [
            'user_id' => $user->id,
            'professional_type' => 'petshop',
        ]);
    }

    /**
     * A lacuna original desta tarefa: cadastrar uma conta de negócio nunca criava
     * `Organization` nem `owner`, e a tela de Equipe não tinha o que gerenciar.
     */
    public function test_generic_professional_registration_creates_organization_with_owner(): void
    {
        $user = User::factory()->create(['user_type' => 'petshop']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-professional', $this->validGenericProfessionalPayload());

        $response->assertOk()
            ->assertJsonPath('organization.business_name', 'Pet Center')
            ->assertJsonPath('organization.organization_type', 'petshop')
            ->assertJsonPath('technical_responsible_invitation_sent', false);

        $this->assertDatabaseHas('organizations', [
            'business_name' => 'Pet Center',
            'organization_type' => 'petshop',
        ]);
        $this->assertDatabaseHas('organization_members', [
            'user_id' => $user->id,
            'role' => 'owner',
            'is_active' => true,
        ]);
        $this->assertTrue($user->fresh()->hasRole('petshop_owner'));
    }

    /**
     * `Professional::updateOrCreate` já era idempotente por `user_id` — a organização
     * precisa acompanhar: completar duas vezes não pode duplicar `Organization` nem o
     * vínculo `owner`.
     */
    public function test_completing_generic_professional_registration_twice_does_not_duplicate_organization(): void
    {
        $user = User::factory()->create(['user_type' => 'petshop']);
        Sanctum::actingAs($user);

        $this->postJson('/api/register/complete-professional', $this->validGenericProfessionalPayload())->assertOk();
        $this->postJson('/api/register/complete-professional', [
            ...$this->validGenericProfessionalPayload(),
            'business_name' => 'Pet Center Renomeado',
        ])->assertOk();

        $this->assertDatabaseCount('organizations', 1);
        $this->assertDatabaseCount('organization_members', 1);
        $this->assertDatabaseHas('organizations', ['business_name' => 'Pet Center Renomeado']);
    }

    public function test_generic_professional_registration_requires_cpf(): void
    {
        $user = User::factory()->create(['user_type' => 'petshop']);
        Sanctum::actingAs($user);

        $payload = $this->validGenericProfessionalPayload();
        unset($payload['cpf']);

        $response = $this->postJson('/api/register/complete-professional', $payload);

        $response->assertStatus(422)->assertJsonValidationErrors(['cpf']);
        $this->assertDatabaseCount('organizations', 0);
    }

    public function test_generic_professional_registration_rejects_invalid_cpf(): void
    {
        $user = User::factory()->create(['user_type' => 'petshop']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-professional', [
            ...$this->validGenericProfessionalPayload(),
            'cpf' => '111.111.111-11',
        ]);

        $response->assertStatus(422)->assertJsonFragment(['cpf' => ['CPF inválido']]);
        $this->assertDatabaseMissing('professionals', ['user_id' => $user->id]);
    }

    public function test_clinic_registration_requires_technical_responsible(): void
    {
        $user = User::factory()->create(['user_type' => 'clinic']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-professional', $this->validGenericProfessionalPayload());

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'technical_responsible_is_self',
                'technical_responsible_name',
                'technical_responsible_crmv',
                'technical_responsible_crmv_state',
            ]);
    }

    /**
     * RT = o próprio representante: seu `Professional` ganha o CRMV formatado, a
     * organização aponta para ele por FK e ele ganha o papel Spatie `clinic_vet` (via cargo
     * `veterinarian` no vínculo), além de continuar `clinic_owner` pelo cadastro próprio.
     */
    public function test_clinic_registration_with_self_as_technical_responsible_grants_veterinarian_role(): void
    {
        $user = User::factory()->create(['user_type' => 'clinic', 'name' => 'Dra. Ana Souza']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-professional', [
            ...$this->validGenericProfessionalPayload(),
            'technical_responsible_is_self' => true,
            'technical_responsible_crmv' => '12345',
            'technical_responsible_crmv_state' => 'SP',
        ]);

        $response->assertOk()->assertJsonPath('technical_responsible_invitation_sent', false);

        $this->assertDatabaseHas('professionals', [
            'user_id' => $user->id,
            'crmv' => 'CRMV/SP 12345',
            'crmv_state' => 'SP',
        ]);
        $organization = Organization::query()->where('business_name', 'Pet Center')->firstOrFail();
        $this->assertSame('Dra. Ana Souza', $organization->technical_responsible_name);
        $this->assertFalse($organization->technical_responsible_verified);
        $this->assertDatabaseHas('organization_members', [
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => 'veterinarian',
        ]);

        $fresh = $user->fresh();
        $this->assertTrue($fresh->hasRole('clinic_owner'));
        $this->assertTrue($fresh->hasRole('clinic_vet'));
        Mail::assertNothingSent();
    }

    /**
     * RT = terceiro: convite disparado por e-mail (`OrganizationInvitationService`), com os
     * campos de texto gravados como fallback até o aceite. O representante continua só
     * `clinic_owner` — não vira veterinário por tabela.
     */
    public function test_clinic_registration_with_third_party_technical_responsible_sends_invitation(): void
    {
        $user = User::factory()->create(['user_type' => 'clinic']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-professional', [
            ...$this->validGenericProfessionalPayload(),
            'technical_responsible_is_self' => false,
            'technical_responsible_name' => 'Dra. Ana Souza',
            'technical_responsible_email' => 'ana.souza@example.com',
            'technical_responsible_crmv' => '12345',
            'technical_responsible_crmv_state' => 'SP',
        ]);

        $response->assertOk()->assertJsonPath('technical_responsible_invitation_sent', true);

        $organization = Organization::query()->where('business_name', 'Pet Center')->firstOrFail();
        $this->assertSame('Dra. Ana Souza', $organization->technical_responsible_name);
        $this->assertNull($organization->technical_responsible_professional_id);

        $this->assertDatabaseHas('organization_invitations', [
            'organization_id' => $organization->id,
            'email' => 'ana.souza@example.com',
            'role' => OrganizationRole::VETERINARIAN->value,
        ]);
        Mail::assertSent(OrganizationInvitationMail::class);

        $this->assertFalse($user->fresh()->hasRole('clinic_vet'));
        $this->assertDatabaseMissing('organization_members', [
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => 'veterinarian',
        ]);
    }

    public function test_generic_professional_registration_rejects_invalid_cnpj(): void
    {
        $user = User::factory()->create(['user_type' => 'petshop']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-professional', [
            ...$this->validGenericProfessionalPayload(),
            'cnpj' => '123',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['cnpj']);
    }

    public function test_generic_professional_registration_rejects_duplicate_cnpj(): void
    {
        Professional::factory()->create([
            'professional_type' => 'petshop',
            'cnpj' => '11222333000181',
        ]);

        $user = User::factory()->create(['user_type' => 'petshop']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-professional', $this->validGenericProfessionalPayload());

        $response->assertStatus(422)
            ->assertJsonPath('duplicate.fields', ['cnpj'])
            ->assertJsonPath('duplicate.action', 'login')
            ->assertJsonFragment(['cnpj' => ['Este CNPJ já está cadastrado.']]);

        $this->assertDatabaseMissing('professionals', ['user_id' => $user->id]);
    }

    public function test_company_completes_registration_successfully(): void
    {
        // NOTA (achado, fora de escopo): `users_user_type_check` no Postgres não inclui
        // 'company' entre os valores aceitos — `user_type` aqui usa o default da coluna
        // ('tutor') de propósito, porque `completeCompany()` nunca checou `user_type` (nem
        // no controller legado, nem aqui). Ver relato desta sessão sobre o bug real: o
        // próprio `/register` (etapa 1) já quebra hoje para `user_type: 'company'`.
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-company', $this->validCompanyPayload());

        $response->assertOk()->assertJsonPath('message', 'Company profile completed successfully!');

        $this->assertDatabaseHas('companies', [
            'user_id' => $user->id,
            'company_name' => 'Acme Pet Foods',
        ]);
        // Concluir o perfil marca `profile_completed` e NÃO mexe em `registration_status`, que é
        // decisão de moderação do admin. O código legado gravava aqui `'completed'`, valor que
        // nenhuma query lê e que tirava a conta tanto do filtro `approved` quanto da fila de
        // aprovação — ver a nota em `RegistrationCompletionService::companyUserData()`.
        $fresh = $user->fresh();

        $this->assertTrue((bool) $fresh->profile_completed);
        $this->assertSame('pending', $fresh->registration_status, 'Empresa parceira nasce pendente e só o admin muda isso.');
    }

    /**
     * Onda 2 do plano de correção do cadastro (2026-09-13): dos 27 campos de
     * `CompleteProfileCompany.vue`, ~18 eram preenchidos na tela e descartados em silêncio
     * porque não existiam em `CompleteCompanyRegistrationRequest::rules()` — sem erro, sem
     * log. Este teste falha se qualquer um dos 11 campos novos (representante legal +
     * qualificação comercial do Clube de Vantagens) voltar a ser descartado.
     */
    public function test_company_completes_registration_persisting_legal_representative_and_qualification_fields(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-company', $this->validCompanyPayload());

        $response->assertOk();

        $company = Company::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertSame('Maria Oliveira', $company->legal_representative_name);
        $this->assertSame('11144477735', $company->legal_representative_cpf);
        $this->assertSame('1980-03-15', $company->legal_representative_birth_date->toDateString());
        $this->assertSame('11988887777', $company->legal_representative_phone);
        $this->assertSame('technology', $company->industry_sector->value);
        $this->assertFalse($company->has_pet_policy);
        $this->assertSame('26_50', $company->estimated_pet_owners->value);
        $this->assertSame('whatsapp', $company->preferred_communication->value);
        $this->assertSame('5k_15k', $company->budget_range->value);
        $this->assertSame('2026-11-01', $company->start_date_preference->toDateString());
        $this->assertSame(['vet_consults', 'vaccines'], $company->interested_services);
    }

    public function test_company_registration_requires_legal_representative_fields(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $payload = $this->validCompanyPayload();
        unset(
            $payload['legal_representative_name'],
            $payload['legal_representative_cpf'],
            $payload['legal_representative_birth_date'],
            $payload['legal_representative_phone'],
        );

        $response = $this->postJson('/api/register/complete-company', $payload);

        $response->assertStatus(422)->assertJsonValidationErrors([
            'legal_representative_name',
            'legal_representative_cpf',
            'legal_representative_birth_date',
            'legal_representative_phone',
        ]);
        $this->assertDatabaseMissing('companies', ['user_id' => $user->id]);
    }

    public function test_company_registration_rejects_invalid_legal_representative_cpf(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-company', [
            ...$this->validCompanyPayload(),
            'legal_representative_cpf' => '111.111.111-11',
        ]);

        $response->assertStatus(422)->assertJsonFragment(['legal_representative_cpf' => ['CPF inválido']]);
        $this->assertDatabaseMissing('companies', ['user_id' => $user->id]);
    }

    /**
     * Decisão desta onda: o CPF do representante legal NÃO entra no jogo de unicidade.
     * A mesma pessoa pode ser tutora na plataforma (linha própria em `users.cpf`) e
     * representante de uma empresa parceira — são papéis diferentes, colunas diferentes,
     * sem relação de unicidade entre elas.
     */
    public function test_legal_representative_cpf_may_repeat_an_existing_user_cpf(): void
    {
        User::factory()->create(['cpf' => '39053344705']);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-company', [
            ...$this->validCompanyPayload(),
            'legal_representative_cpf' => '390.533.447-05',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('companies', [
            'user_id' => $user->id,
            'legal_representative_cpf' => '39053344705',
        ]);
    }

    /**
     * Duas empresas parceiras com o mesmo representante legal é plausível e legítimo
     * (ex.: contador ou sócio que representa mais de uma empresa) — não há unicidade entre
     * `companies.legal_representative_cpf` de linhas diferentes.
     */
    public function test_legal_representative_cpf_may_repeat_across_two_companies(): void
    {
        Company::factory()->create(['legal_representative_cpf' => '11144477735']);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-company', [
            ...$this->validCompanyPayload(),
            'cnpj' => '22.333.444/0001-52',
            'legal_representative_cpf' => '111.444.777-35',
        ]);

        $response->assertOk();
        $this->assertDatabaseCount('companies', 2);
    }

    public function test_company_registration_requires_industry_sector(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $payload = $this->validCompanyPayload();
        unset($payload['industry_sector']);

        $response = $this->postJson('/api/register/complete-company', $payload);

        $response->assertStatus(422)->assertJsonValidationErrors(['industry_sector']);
        $this->assertDatabaseMissing('companies', ['user_id' => $user->id]);
    }

    #[DataProvider('closedSetCompanyFieldsProvider')]
    public function test_company_registration_rejects_invalid_value_for_closed_set_field(string $field): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-company', [
            ...$this->validCompanyPayload(),
            $field => 'nao-existe-nesta-lista',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors([$field]);
    }

    /** @return array<string, list<string>> */
    public static function closedSetCompanyFieldsProvider(): array
    {
        return [
            'setor da empresa' => ['industry_sector'],
            'estimativa de pet owners' => ['estimated_pet_owners'],
            'canal de contato preferido' => ['preferred_communication'],
            'faixa de investimento' => ['budget_range'],
        ];
    }

    public function test_company_registration_rejects_invalid_cnpj(): void
    {
        // Ver nota em test_company_completes_registration_successfully sobre `user_type`.
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-company', [
            ...$this->validCompanyPayload(),
            'cnpj' => '123',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['cnpj']);
        $this->assertDatabaseMissing('companies', ['user_id' => $user->id]);
    }

    public function test_company_registration_rejects_duplicate_cnpj(): void
    {
        Company::factory()->create(['cnpj' => '11222333000181']);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-company', $this->validCompanyPayload());

        $response->assertStatus(422)
            ->assertJsonPath('duplicate.fields', ['cnpj'])
            ->assertJsonPath('duplicate.action', 'login')
            ->assertJsonFragment(['cnpj' => ['Este CNPJ já está cadastrado.']]);

        $this->assertDatabaseMissing('companies', ['user_id' => $user->id]);
    }

    /**
     * Mesma proteção de `completeVet`: `completeCompany` também ganhou `DB::transaction()`
     * nesta onda. Chamado direto no service para simular a colisão sobrevivendo à validação
     * do Form Request (race condition entre duas requisições simultâneas).
     */
    public function test_completing_company_registration_rolls_back_user_update_when_cnpj_collides_in_database(): void
    {
        Company::factory()->create(['cnpj' => '11222333000181']);

        $user = User::factory()->create(['profile_completed' => false]);

        $threw = false;

        try {
            app(RegistrationCompletionService::class)->completeCompany($user, $this->validCompanyPayload());
        } catch (QueryException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Esperava QueryException por colisão de CNPJ no banco.');
        $this->assertFalse((bool) $user->fresh()->profile_completed);
        $this->assertDatabaseMissing('companies', ['user_id' => $user->id]);
    }

    /** @return array<string, mixed> */
    private function validCompanyPayload(): array
    {
        return [
            'company_name' => 'Acme Pet Foods',
            'cnpj' => '11.222.333/0001-81',
            'contact_name' => 'João Silva',
            'phone' => '11999999999',
            'employee_count' => '50-100',
            'benefit_type' => 'desconto',
            'address' => 'Av. Paulista',
            'number' => '1000',
            'neighborhood' => 'Bela Vista',
            'city' => 'São Paulo',
            'state' => 'SP',
            'zip_code' => '01310-000',
            'legal_representative_name' => 'Maria Oliveira',
            'legal_representative_cpf' => '111.444.777-35',
            'legal_representative_birth_date' => '1980-03-15',
            'legal_representative_phone' => '11988887777',
            'industry_sector' => 'technology',
            'has_pet_policy' => false,
            'estimated_pet_owners' => '26_50',
            'preferred_communication' => 'whatsapp',
            'budget_range' => '5k_15k',
            'start_date_preference' => '2026-11-01',
            'interested_services' => ['vet_consults', 'vaccines'],
        ];
    }

    /** @return array<string, mixed> */
    private function validVetPayload(): array
    {
        return [
            'cpf' => '390.533.447-05',
            'birth_date' => '1985-05-20',
            'address' => 'Rua dos Veterinários',
            'number' => '50',
            'neighborhood' => 'Jardins',
            'city' => 'São Paulo',
            'state' => 'SP',
            'zip_code' => '01400-000',
            'university' => 'USP',
            'graduation_year' => 2010,
            'crmv' => '12345',
            'crmv_state' => 'SP',
            'experience_years' => 10,
            'opening_hours' => '08:00',
            'closing_hours' => '18:00',
            'working_days' => ['monday', 'tuesday', 'wednesday'],
            // Obrigatório para `vet` (docs/segmentacao-cadastro-profissional.md §2) —
            // `ProfessionalCapabilityRegistry`/`ProfessionalCapabilityTest` cobrem a matriz
            // completa; aqui é só o mínimo para o cadastro passar.
            'species_served' => ['dog', 'cat'],
        ];
    }

    /** @return array<string, mixed> */
    private function validGenericProfessionalPayload(): array
    {
        return [
            'cpf' => '390.533.447-05',
            'business_name' => 'Pet Center',
            'cnpj' => '11.222.333/0001-81',
            'address' => 'Rua do Comércio',
            'number' => '200',
            'neighborhood' => 'Centro',
            'city' => 'São Paulo',
            'state' => 'SP',
            'zip_code' => '01000-000',
            'opening_hours' => '09:00',
            'closing_hours' => '19:00',
            'working_days' => ['monday', 'tuesday', 'wednesday'],
            // Obrigatório para `clinic`/`petshop` (os dois tipos usados por este fixture nos
            // testes existentes) — ver nota em `validVetPayload()`.
            'species_served' => ['dog', 'cat'],
        ];
    }
}
