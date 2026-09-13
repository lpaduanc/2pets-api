<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Professional;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cobre o bug corrigido na Fase 2 do plano de otimizacao: `PUT /profile`
 * mudava o endereco textual mas nunca re-geocodificava, deixando o
 * profissional preso no endereco antigo na busca por geolocalizacao para
 * sempre. Ver ProfileUpdateService.
 *
 * Tambem cobre o conserto do contrato de `GET/PUT /api/profile`: shape unico
 * com `address` aninhado e blocos `professional`/`company`, suporte a
 * cadastro/edicao completa (cpf, birth_date, gender, occupation) e as
 * garantias de mass-assignment (campos somente leitura e isolamento entre
 * usuarios). Ver UserProfileResource e LinkedProfileUpdateService.
 */
class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const PROFILE_STRUCTURE = [
        'id', 'name', 'email', 'role', 'user_type', 'phone', 'cpf',
        'birth_date', 'gender', 'occupation', 'profile_completed',
        'registration_status', 'email_verified', 'pets_count', 'avatar_url', 'created_at',
        'address' => ['street', 'number', 'complement', 'neighborhood', 'city', 'state', 'zip_code'],
        'professional', 'company',
    ];

    public function test_changing_address_triggers_regeocoding_and_updates_location(): void
    {
        Http::fake([
            'maps.googleapis.com/*' => Http::response([
                'status' => 'OK',
                'results' => [[
                    'geometry' => ['location' => ['lat' => -22.9099, 'lng' => -47.0626]],
                ]],
            ]),
        ]);

        $user = User::factory()->tutor()->create([
            'address' => 'Rua Antiga, 100',
            'city' => 'São Paulo',
            'state' => 'SP',
            'zip_code' => '01000-000',
            'latitude' => -23.5505,
            'longitude' => -46.6333,
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/profile', [
            'address' => 'Rua Nova, 200',
            'city' => 'Campinas',
            'state' => 'SP',
            'zip_code' => '13000-000',
        ]);

        $response->assertOk();

        $user->refresh();
        $this->assertEqualsWithDelta(-22.9099, (float) $user->latitude, 0.0001);
        $this->assertEqualsWithDelta(-47.0626, (float) $user->longitude, 0.0001);

        $point = DB::selectOne(
            'SELECT ST_X(location::geometry) AS lng, ST_Y(location::geometry) AS lat FROM users WHERE id = ?',
            [$user->id]
        );
        $this->assertEqualsWithDelta(-47.0626, $point->lng, 0.0001);
        $this->assertEqualsWithDelta(-22.9099, $point->lat, 0.0001);
    }

    public function test_updating_unrelated_field_does_not_call_geocoding(): void
    {
        Http::fake();

        $user = User::factory()->tutor()->create([
            'latitude' => -23.5505,
            'longitude' => -46.6333,
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/profile', ['name' => 'Nome Atualizado']);

        $response->assertOk();
        Http::assertNothingSent();
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        $user = User::factory()->tutor()->create();

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/profile', [
            'current_password' => 'senha-errada',
            'new_password' => 'nova-senha-123',
            'new_password_confirmation' => 'nova-senha-123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('current_password');
    }

    public function test_current_password_without_new_password_returns_422_not_500(): void
    {
        $user = User::factory()->tutor()->create();

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/profile', [
            'current_password' => 'password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('new_password');
    }

    public function test_get_profile_returns_nested_address_shape_for_tutor(): void
    {
        $user = User::factory()->tutor()->create([
            'address' => 'Rua das Flores',
            'number' => '123',
            'complement' => 'Apto 45',
            'neighborhood' => 'Centro',
            'city' => 'São Paulo',
            'state' => 'SP',
            'zip_code' => '01000-000',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/profile');

        $response->assertOk();
        $response->assertJsonStructure(self::PROFILE_STRUCTURE);
        $response->assertJson([
            'address' => [
                'street' => 'Rua das Flores',
                'number' => '123',
                'complement' => 'Apto 45',
                'neighborhood' => 'Centro',
                'city' => 'São Paulo',
                'state' => 'SP',
                'zip_code' => '01000-000',
            ],
            'professional' => null,
            'company' => null,
        ]);
        $this->assertArrayNotHasKey('city', $response->json());
    }

    public function test_get_profile_returns_professional_block_for_professional_user(): void
    {
        $professional = Professional::factory()->veterinarian()->create();
        $user = $professional->user;

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/profile');

        $response->assertOk();
        $response->assertJsonPath('professional.professional_type', 'vet');
        $response->assertJsonPath('professional.crmv', $professional->crmv);
        $response->assertJsonPath('company', null);
        $response->assertJsonStructure(['professional' => ['is_crmv_verified', 'average_rating', 'total_reviews']]);
    }

    public function test_get_profile_normalizes_time_columns_to_hi_format(): void
    {
        $professional = Professional::factory()->veterinarian()->create([
            'opening_hours' => '08:00:00',
            'closing_hours' => '20:00:00',
        ]);

        Sanctum::actingAs($professional->user);

        $response = $this->getJson('/api/profile');

        $response->assertOk();
        $response->assertJsonPath('professional.opening_hours', '08:00');
        $response->assertJsonPath('professional.closing_hours', '20:00');
    }

    /**
     * Bug real: a coluna `professionals.opening_hours`/`closing_hours` e
     * `time` no Postgres e chega crua do PDO como `H:i:s`. Sem normalizacao
     * no resource, o GET devolvia `"08:00:00"` e o PUT — que a tela "Meu
     * Perfil" alimenta com o proprio payload do GET — rejeitava com 422
     * porque a regra exigia `date_format:H:i`. Nenhuma clinica com horario
     * preenchido conseguia salvar qualquer campo do perfil.
     */
    public function test_put_accepts_the_exact_payload_returned_by_get_for_time_fields(): void
    {
        $professional = Professional::factory()->veterinarian()->create([
            'opening_hours' => '08:00:00',
            'closing_hours' => '20:00:00',
        ]);

        Sanctum::actingAs($professional->user);

        $getResponse = $this->getJson('/api/profile');
        $getResponse->assertOk();

        $putResponse = $this->putJson('/api/profile', [
            'professional' => [
                'opening_hours' => $getResponse->json('professional.opening_hours'),
                'closing_hours' => $getResponse->json('professional.closing_hours'),
            ],
        ]);

        $putResponse->assertOk();
    }

    public function test_put_still_accepts_legacy_his_time_format(): void
    {
        $professional = Professional::factory()->veterinarian()->create();

        Sanctum::actingAs($professional->user);

        $response = $this->putJson('/api/profile', [
            'professional' => [
                'opening_hours' => '08:00:00',
                'closing_hours' => '20:00:00',
            ],
        ]);

        $response->assertOk();

        $professional->refresh();
        $this->assertSame('08:00:00', $professional->opening_hours);
        $this->assertSame('20:00:00', $professional->closing_hours);
    }

    public function test_get_profile_returns_company_block_for_company_user(): void
    {
        $company = Company::factory()->create();
        $user = $company->user;

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/profile');

        $response->assertOk();
        $response->assertJsonPath('company.company_name', $company->company_name);
        $response->assertJsonPath('company.cnpj', $company->cnpj);
        $response->assertJsonPath('professional', null);
    }

    public function test_partial_update_of_phone_does_not_clear_other_fields(): void
    {
        $user = User::factory()->tutor()->create([
            'name' => 'Nome Original',
            'address' => 'Rua Original',
            'city' => 'São Paulo',
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/profile', ['phone' => '(11) 99999-0000']);

        $response->assertOk();

        $user->refresh();
        $this->assertSame('(11) 99999-0000', $user->phone);
        $this->assertSame('Nome Original', $user->name);
        $this->assertSame('Rua Original', $user->address);
        $this->assertSame('São Paulo', $user->city);
    }

    public function test_update_with_nested_address_persists_columns_and_triggers_regeocoding(): void
    {
        Http::fake([
            'maps.googleapis.com/*' => Http::response([
                'status' => 'OK',
                'results' => [[
                    'geometry' => ['location' => ['lat' => -22.9099, 'lng' => -47.0626]],
                ]],
            ]),
        ]);

        $user = User::factory()->tutor()->create([
            'address' => 'Rua Antiga',
            'city' => 'São Paulo',
            'state' => 'SP',
            'zip_code' => '01000-000',
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/profile', [
            'address' => [
                'street' => 'Rua Nova',
                'number' => '200',
                'complement' => 'Fundos',
                'neighborhood' => 'Jardins',
                'city' => 'Campinas',
                'state' => 'SP',
                'zip_code' => '13000-000',
            ],
        ]);

        $response->assertOk();

        $user->refresh();
        $this->assertSame('Rua Nova', $user->address);
        $this->assertSame('200', $user->number);
        $this->assertSame('Fundos', $user->complement);
        $this->assertSame('Jardins', $user->neighborhood);
        $this->assertSame('Campinas', $user->city);
        $this->assertEqualsWithDelta(-22.9099, (float) $user->latitude, 0.0001);
        $this->assertEqualsWithDelta(-47.0626, (float) $user->longitude, 0.0001);
    }

    public function test_update_persists_cpf_birth_date_and_gender(): void
    {
        $user = User::factory()->tutor()->create();

        Sanctum::actingAs($user);

        // CPF com dígitos verificadores válidos: a fixture anterior (`123.456.789-00`) não era um
        // CPF real e só passava porque a regra era `digits:11` pura.
        $response = $this->putJson('/api/profile', [
            'cpf' => '390.533.447-05',
            'birth_date' => '1990-01-31',
            'gender' => 'female',
            'occupation' => 'Veterinária',
        ]);

        $response->assertOk();

        $user->refresh();
        $this->assertSame('39053344705', $user->cpf);
        $this->assertSame('1990-01-31', $user->birth_date->format('Y-m-d'));
        $this->assertSame('female', $user->gender);
        $this->assertSame('Veterinária', $user->occupation);
    }

    /**
     * Regressão: editar perfil validava CPF só por `digits:11`, enquanto os três fluxos de
     * cadastro exigiam os dígitos verificadores. Um CNPJ colado num campo de máscara curta chega
     * truncado em 11 algarismos e passava — foi como uma conta de clínica em dev acabou com
     * `cpf = 48328865000`, que são os 11 primeiros dígitos do CNPJ dela.
     */
    public function test_update_rejects_a_cnpj_truncated_into_the_cpf_field(): void
    {
        $user = User::factory()->tutor()->create();

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/profile', [
            'cpf' => '48328865000',
        ]);

        $response->assertStatus(422)->assertJsonFragment(['cpf' => ['CPF inválido']]);
        $this->assertNotSame('48328865000', $user->fresh()->cpf);
    }

    public function test_update_rejects_cpf_already_used_by_another_user(): void
    {
        User::factory()->tutor()->create(['cpf' => '111.444.777-35']);
        $user = User::factory()->tutor()->create();

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/profile', ['cpf' => '111.444.777-35']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('cpf');
    }

    public function test_update_ignores_read_only_and_privileged_fields(): void
    {
        $professional = Professional::factory()->veterinarian()->create(['total_reviews' => 3]);
        $user = $professional->user;
        $originalRole = $user->role;

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/profile', [
            'role' => 'admin',
            'registration_status' => 'approved',
            'profile_completed' => false,
            'professional' => [
                'is_crmv_verified' => true,
                'average_rating' => 5,
                'total_reviews' => 999,
            ],
        ]);

        $response->assertOk();

        $user->refresh();
        $professional->refresh();

        $this->assertSame($originalRole, $user->role);
        $this->assertFalse($professional->is_crmv_verified);
        $this->assertSame(3, $professional->total_reviews);
    }

    public function test_update_cannot_change_professional_of_another_user(): void
    {
        $ownProfessional = Professional::factory()->veterinarian()->create(['business_name' => 'Clinica A']);
        $otherProfessional = Professional::factory()->veterinarian()->create(['business_name' => 'Clinica B']);

        Sanctum::actingAs($ownProfessional->user);

        $response = $this->putJson('/api/profile', [
            'professional' => ['business_name' => 'Clinica A Atualizada'],
        ]);

        $response->assertOk();

        $ownProfessional->refresh();
        $otherProfessional->refresh();

        $this->assertSame('Clinica A Atualizada', $ownProfessional->business_name);
        $this->assertSame('Clinica B', $otherProfessional->business_name);
    }

    public function test_get_profile_returns_technical_responsible_fields_for_clinic(): void
    {
        $professional = Professional::factory()->clinic()->create([
            'technical_responsible_name' => 'Dra. Ana Souza',
            'technical_responsible_crmv' => '12345',
            'technical_responsible_crmv_state' => 'SP',
        ]);

        Sanctum::actingAs($professional->user);

        $response = $this->getJson('/api/profile');

        $response->assertOk();
        $response->assertJsonPath('professional.technical_responsible_name', 'Dra. Ana Souza');
        $response->assertJsonPath('professional.technical_responsible_crmv', '12345');
        $response->assertJsonPath('professional.technical_responsible_crmv_state', 'SP');
    }

    public function test_update_persists_technical_responsible_for_clinic(): void
    {
        $professional = Professional::factory()->clinic()->create([
            'technical_responsible_name' => 'Dra. Ana Souza',
            'technical_responsible_crmv' => '12345',
            'technical_responsible_crmv_state' => 'SP',
        ]);

        Sanctum::actingAs($professional->user);

        $response = $this->putJson('/api/profile', [
            'professional' => [
                'technical_responsible_name' => 'Dr. Carlos Lima',
                'technical_responsible_crmv' => '54321',
                'technical_responsible_crmv_state' => 'RJ',
            ],
        ]);

        $response->assertOk();

        $professional->refresh();
        $this->assertSame('Dr. Carlos Lima', $professional->technical_responsible_name);
        $this->assertSame('54321', $professional->technical_responsible_crmv);
        $this->assertSame('RJ', $professional->technical_responsible_crmv_state);
    }

    public function test_update_rejects_blank_technical_responsible_for_clinic(): void
    {
        $professional = Professional::factory()->clinic()->create([
            'technical_responsible_name' => 'Dra. Ana Souza',
            'technical_responsible_crmv' => '12345',
            'technical_responsible_crmv_state' => 'SP',
        ]);

        Sanctum::actingAs($professional->user);

        $response = $this->putJson('/api/profile', [
            'professional' => [
                'professional_type' => 'clinic',
                'technical_responsible_name' => '',
                'technical_responsible_crmv' => '',
                'technical_responsible_crmv_state' => '',
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'professional.technical_responsible_name',
            'professional.technical_responsible_crmv',
            'professional.technical_responsible_crmv_state',
        ]);
    }

    public function test_update_without_technical_responsible_fields_does_not_regress_vet_freelancer(): void
    {
        $professional = Professional::factory()->veterinarian()->create();

        Sanctum::actingAs($professional->user);

        $response = $this->putJson('/api/profile', [
            'professional' => ['description' => 'Atendimento domiciliar'],
        ]);

        $response->assertOk();

        $professional->refresh();
        $this->assertSame('Atendimento domiciliar', $professional->description);
    }

    public function test_update_rejects_blank_technical_responsible_when_professional_type_is_omitted(): void
    {
        $professional = Professional::factory()->clinic()->create([
            'technical_responsible_name' => 'Dra. Ana Souza',
            'technical_responsible_crmv' => '12345',
            'technical_responsible_crmv_state' => 'SP',
        ]);

        Sanctum::actingAs($professional->user);

        $response = $this->putJson('/api/profile', [
            'professional' => [
                'description' => 'Nova descricao da clinica',
                'technical_responsible_name' => '',
                'technical_responsible_crmv' => '',
                'technical_responsible_crmv_state' => '',
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'professional.technical_responsible_name',
            'professional.technical_responsible_crmv',
            'professional.technical_responsible_crmv_state',
        ]);
    }

    public function test_put_response_shape_matches_get_response_shape(): void
    {
        $professional = Professional::factory()->veterinarian()->create();
        $user = $professional->user;

        Sanctum::actingAs($user);

        $getResponse = $this->getJson('/api/profile');
        $putResponse = $this->putJson('/api/profile', ['occupation' => 'Clínico geral']);

        $getResponse->assertOk();
        $putResponse->assertOk();
        $putResponse->assertJsonStructure(self::PROFILE_STRUCTURE);

        $this->assertSame(
            array_keys($getResponse->json()),
            array_keys($putResponse->json())
        );
        $this->assertSame(
            array_keys($getResponse->json('address')),
            array_keys($putResponse->json('address'))
        );
        $this->assertSame(
            array_keys($getResponse->json('professional')),
            array_keys($putResponse->json('professional'))
        );
    }

    /**
     * Pendência registrada na Onda 3 do plano de correção do cadastro: os 18 campos de
     * "Diferenciais e Facilidades" + `species_served`/`sizes_served` podiam ser preenchidos no
     * cadastro mas não podiam ser editados depois — `UpdateProfileRequest` não tinha regra
     * nenhuma para eles. Fechado na Onda 4 reaproveitando `HasProfessionalCapabilityRules`.
     */
    public function test_update_persists_capability_fields_allowed_for_the_professional_type(): void
    {
        $professional = Professional::factory()->petshop()->create();
        Sanctum::actingAs($professional->user);

        $response = $this->putJson('/api/profile', [
            'professional' => [
                'parking_available' => true,
                'accepts_credit_card' => true,
                'delivery_available' => true,
                'species_served' => ['dog', 'cat'],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('professional.parking_available', true)
            ->assertJsonPath('professional.species_served', ['dog', 'cat']);

        $professional->refresh();
        $this->assertTrue($professional->parking_available);
        $this->assertTrue($professional->accepts_credit_card);
        $this->assertTrue($professional->delivery_available);
        $this->assertSame(['dog', 'cat'], $professional->species_served);
    }

    public function test_update_rejects_capability_field_not_allowed_for_the_professional_type(): void
    {
        // Vet volante não tem prédio — `parking_available` é proibido para o tipo (ver
        // `ProfessionalCapabilityDefinitions`), mesma dupla trava do cadastro.
        $professional = Professional::factory()->veterinarian()->create();
        Sanctum::actingAs($professional->user);

        $response = $this->putJson('/api/profile', [
            'professional' => ['parking_available' => true],
        ]);

        $response->assertStatus(422)->assertJsonFragment([
            'professional.parking_available' => ['O campo "Estacionamento" não é permitido para o tipo de cadastro selecionado.'],
        ]);

        $this->assertNull($professional->refresh()->parking_available);
    }

    /**
     * Bug real reportado em produção: o formulário sempre serializa o campo escondido
     * (`false`, não omite a chave) — `prohibited` do Laravel rejeitava `false` como se
     * fosse uma tentativa de burlar a trava. `false` precisa passar (a UI já escondeu o
     * campo certo) e a coluna continua sem ser gravada, porque não se aplica ao tipo.
     */
    public function test_update_accepts_false_for_a_capability_field_not_allowed_and_does_not_persist_it(): void
    {
        $professional = Professional::factory()->veterinarian()->create();
        Sanctum::actingAs($professional->user);

        $response = $this->putJson('/api/profile', [
            'professional' => ['parking_available' => false],
        ]);

        $response->assertOk();
        $this->assertNull($professional->refresh()->parking_available);
    }

    /**
     * A neutralização de um campo não aplicável (ver teste acima) não pode se generalizar
     * para "todo PATCH reseta os 20 campos de capacidade": um PATCH que nem menciona
     * `accepts_credit_card` tem que preservar o valor já salvo dele.
     */
    public function test_update_of_an_unrelated_field_does_not_reset_previously_saved_capability_fields(): void
    {
        $professional = Professional::factory()->petshop()->create(['accepts_credit_card' => true]);
        Sanctum::actingAs($professional->user);

        $response = $this->putJson('/api/profile', [
            'professional' => ['business_name' => 'Novo Nome'],
        ]);

        $response->assertOk();
        $this->assertTrue($professional->refresh()->accepts_credit_card);
    }

    public function test_update_without_capability_fields_does_not_require_them(): void
    {
        // Patch parcial: editar só `business_name` não deve exigir `species_served` de novo,
        // mesmo sendo `required` na conclusão de cadastro para `petshop`.
        $professional = Professional::factory()->petshop()->create(['species_served' => null]);
        Sanctum::actingAs($professional->user);

        $response = $this->putJson('/api/profile', [
            'professional' => ['business_name' => 'Novo Nome'],
        ]);

        $response->assertOk();
        $this->assertSame('Novo Nome', $professional->refresh()->business_name);
    }
}
