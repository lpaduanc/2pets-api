<?php

namespace Tests\Feature;

use App\Models\Professional;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Onda 3 da segmentação de cadastro (`docs/segmentacao-cadastro-profissional.md`): a dupla
 * trava HTTP (`HasProfessionalCapabilityRules`), a persistência real dos 18 campos de
 * "Diferenciais e Facilidades" antes descartados, e o endpoint que serve a matriz ao front.
 * A matriz em si (dado puro) está coberta por `Tests\Unit\ProfessionalCapabilityRegistryTest`.
 */
class ProfessionalCapabilitySegmentationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['maps.googleapis.com/*' => Http::response(['status' => 'ZERO_RESULTS', 'results' => []])]);
        Mail::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_grooming_registration_rejects_clinical_service_category(): void
    {
        $user = User::factory()->create(['user_type' => 'grooming']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-professional', [
            ...$this->businessPayload(),
            'species_served' => ['dog'],
            'sizes_served' => ['medium'],
            'services_offered' => ['consultation'],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['services_offered.0'])
            ->assertJsonFragment(['services_offered.0' => ['Este serviço não está disponível para o tipo de cadastro selecionado.']]);

        $this->assertDatabaseMissing('professionals', ['user_id' => $user->id]);
    }

    /**
     * A queixa literal do dono do produto: vet volante não tem prédio, então não pode
     * declarar estacionamento — nem que alguém force o payload por fora da UI.
     */
    public function test_vet_registration_rejects_structural_facility_field(): void
    {
        $user = User::factory()->create(['user_type' => 'vet', 'cpf' => null]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-professional', [
            ...$this->vetPayload(),
            'parking_available' => true,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['parking_available'])
            ->assertJsonFragment(['parking_available' => ['O campo "Estacionamento" não é permitido para o tipo de cadastro selecionado.']]);
    }

    /**
     * Bug real reportado em produção: o formulário de cadastro manda `false` (não omite a
     * chave) para todo campo de "Diferenciais e Facilidades" que a tela esconde — a regra
     * nativa `prohibited` do Laravel trata `false` como "valor presente" e rejeitava um vet
     * que simplesmente NÃO tem estacionamento. `false` precisa passar (a UI já escondeu o
     * campo corretamente; o cliente só serializou o valor neutro do formulário) — e a
     * coluna continua sem ser gravada, porque o campo não existe para este tipo.
     */
    public function test_vet_registration_accepts_false_for_a_structural_facility_field(): void
    {
        $user = User::factory()->create(['user_type' => 'vet', 'cpf' => null]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-professional', [
            ...$this->vetPayload(),
            'parking_available' => false,
        ]);

        $response->assertOk();

        $professional = Professional::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertNull($professional->parking_available);
    }

    /**
     * Mesmo par (`false` aceito e não persistido / `true` rejeitado com 422), num segundo
     * tipo e num diferencial (não facilidade estrutural): petshop não tem emergência 24h —
     * só clínica tem, ver `ProfessionalCapabilityDefinitions`.
     */
    public function test_petshop_registration_accepts_false_for_an_unavailable_differential(): void
    {
        $user = User::factory()->create(['user_type' => 'petshop']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-professional', [
            ...$this->businessPayload(),
            'species_served' => ['dog'],
            'sizes_served' => ['medium'],
            'emergency_24h' => false,
        ]);

        $response->assertOk();

        $professional = Professional::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertNull($professional->emergency_24h);
    }

    public function test_petshop_registration_rejects_true_for_an_unavailable_differential(): void
    {
        $user = User::factory()->create(['user_type' => 'petshop']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-professional', [
            ...$this->businessPayload(),
            'species_served' => ['dog'],
            'sizes_served' => ['medium'],
            'emergency_24h' => true,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['emergency_24h'])
            ->assertJsonFragment(['emergency_24h' => ['O campo "Atendimento 24 horas" não é permitido para o tipo de cadastro selecionado.']]);
    }

    public function test_species_served_is_required_and_rejects_an_empty_list(): void
    {
        $user = User::factory()->create(['user_type' => 'petshop']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-professional', [
            ...$this->businessPayload(),
            'species_served' => [],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['species_served']);
    }

    /**
     * Laboratório não hospeda animal — porte atendido não se aplica
     * (`docs/segmentacao-cadastro-profissional.md` §2, linha "Portes atendidos").
     */
    public function test_laboratory_registration_prohibits_sizes_served(): void
    {
        $user = User::factory()->create(['user_type' => 'laboratory']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-professional', [
            ...$this->businessPayload(),
            'technical_responsible_is_self' => true,
            'technical_responsible_crmv' => '12345',
            'technical_responsible_crmv_state' => 'SP',
            'species_served' => ['dog'],
            'sizes_served' => ['small'],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['sizes_served']);
    }

    /**
     * Fecha o gap que dava fantasma na tela "Diferenciais e Facilidades"
     * (`CompleteProfileProfessional.vue:1051-1081`): submeter e reler tem que devolver o
     * mesmo valor, para clínica (estrutura física + diferenciais + RT como o próprio
     * representante).
     */
    public function test_clinic_registration_persists_facilities_and_differentials(): void
    {
        $user = User::factory()->create(['user_type' => 'clinic', 'name' => 'Dra. Ana Souza']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-professional', [
            ...$this->businessPayload(),
            'technical_responsible_is_self' => true,
            'technical_responsible_crmv' => '12345',
            'technical_responsible_crmv_state' => 'SP',
            'species_served' => ['dog', 'cat'],
            'sizes_served' => ['medium'],
            'services_offered' => ['consultation', 'surgery'],
            'parking_available' => true,
            'wheelchair_accessible' => true,
            'accepts_credit_card' => true,
            'accepts_pet_insurance' => false,
            'emergency_24h' => true,
            'exam_rooms_count' => 3,
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('professionals', [
            'user_id' => $user->id,
            'parking_available' => true,
            'wheelchair_accessible' => true,
            'accepts_credit_card' => true,
            'accepts_pet_insurance' => false,
            'emergency_24h' => true,
            'exam_rooms_count' => 3,
        ]);

        $professional = Professional::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(['dog', 'cat'], $professional->species_served);
        $this->assertSame(['medium'], $professional->sizes_served);

        // Critério de aceite do documento: reabrir o perfil (GET) tem que devolver o mesmo
        // valor salvo — antes desta onda, `ProfessionalProfileResource` não expunha nenhum
        // dos 18 campos (nem os pré-existentes `equipment`/`certifications`).
        $this->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('professional.parking_available', true)
            ->assertJsonPath('professional.accepts_credit_card', true)
            ->assertJsonPath('professional.accepts_pet_insurance', false)
            ->assertJsonPath('professional.emergency_24h', true)
            ->assertJsonPath('professional.exam_rooms_count', 3)
            ->assertJsonPath('professional.species_served', ['dog', 'cat'])
            ->assertJsonPath('professional.sizes_served', ['medium']);
    }

    /**
     * `docs/segmentacao-cadastro-profissional.md` §6: a coluna `service_radius_km` sempre
     * existiu em `professionals`/`organizations`, mas o Form Request de negócio genérico
     * nunca a lia — banho e tosa móvel morria no envio.
     */
    public function test_grooming_registration_persists_service_radius_km(): void
    {
        $user = User::factory()->create(['user_type' => 'grooming']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-professional', [
            ...$this->businessPayload(),
            'species_served' => ['dog'],
            'sizes_served' => ['small'],
            'mobile_service' => true,
            'service_radius_km' => 15,
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('professionals', ['user_id' => $user->id, 'service_radius_km' => 15]);
        $this->assertDatabaseHas('organizations', ['business_name' => 'Pet Center', 'service_radius_km' => 15]);
    }

    public function test_professional_schema_endpoint_serves_the_capability_matrix(): void
    {
        Sanctum::actingAs(User::factory()->create());

        // `Accept-Language` explícito: sem ele, o harness de teste do Symfony
        // (`Request::create()`) injeta `'en-us,en;q=0.5'` por padrão, o que faria este
        // teste em pt-BR falhar por um artefato do cliente de teste, não da API real (uma
        // requisição de produção sem o header cai em pt-BR — ver `AppLocaleTest`).
        $response = $this->getJson('/api/register/professional-schema', ['Accept-Language' => 'pt-BR']);

        $response->assertOk()
            ->assertJsonPath('types.vet.identification', 'cpf')
            ->assertJsonPath('types.vet.requires_crmv', true)
            ->assertJsonPath('types.vet.has_physical_address', false)
            ->assertJsonPath('types.vet.service_radius', 'required')
            ->assertJsonPath('types.clinic.requires_technical_responsible', true)
            ->assertJsonPath('labels.species.dog', 'Cão');

        $this->assertContains('consultation', $response->json('types.vet.service_categories'));
        $this->assertNotContains('surgery', $response->json('types.vet.service_categories'));
        $this->assertSame([], $response->json('types.vet.facilities'));
    }

    /**
     * `docs/equipamento-vet-volante-e-marketplace-b2b.md` §2.1/§5: `vet` ganha equipamento
     * portátil, `imaging`/`laboratory` entram no catálogo, e o front recebe os dois mapas
     * novos (dependência serviço↔equipamento e equipamento↔documento) para desabilitar o
     * serviço na UI antes de a API precisar rejeitar.
     */
    public function test_professional_schema_endpoint_exposes_vet_equipment_and_dependency_maps(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/register/professional-schema');

        $response->assertOk();

        $this->assertContains('ultrasound_machine', $response->json('types.vet.equipment'));
        $this->assertContains('imaging', $response->json('types.vet.service_categories'));
        $this->assertContains('laboratory', $response->json('types.vet.service_categories'));
        $this->assertNotContains('surgery_room', $response->json('types.vet.equipment'));

        $this->assertSame(
            ['xray_machine', 'ultrasound_machine', 'ecg_machine'],
            $response->json('service_equipment_dependencies.imaging'),
        );
        $this->assertSame('radiology_license', $response->json('equipment_documents.xray_machine'));
        $this->assertArrayNotHasKey('ultrasound_machine', $response->json('equipment_documents'));
    }

    /**
     * P0 2026-09-13: `service_items` é o catálogo granular (fonte única, ver
     * `ServiceCatalog::itemsPayload()`) — o front deixa de manter
     * `constants/serviceCategoryMap.js` em paralelo e passa a ler `value`/`label`/`category`
     * daqui.
     */
    public function test_professional_schema_endpoint_exposes_the_granular_service_catalog(): void
    {
        Sanctum::actingAs(User::factory()->create());

        // Ver comentário equivalente em `test_professional_schema_endpoint_serves_the_capability_matrix`.
        $response = $this->getJson('/api/register/professional-schema', ['Accept-Language' => 'pt-BR']);

        $response->assertOk();

        $serviceItems = collect($response->json('service_items'))->keyBy('value');

        $this->assertSame('Vermifugação', $serviceItems['deworming']['label']);
        $this->assertSame('vaccination', $serviceItems['deworming']['category']);
        $this->assertSame('imaging', $serviceItems['xray']['category']);
        $this->assertArrayNotHasKey('consultation', $serviceItems);
    }

    /**
     * Critério de aceite (`equipamento-vet-volante-e-marketplace-b2b.md` §5): `vet` com
     * `equipment: ['ultrasound_machine']` consegue oferecer `imaging` — a API hoje rejeitava
     * com 422 mesmo o vet tendo declarado o aparelho, porque `equipment` era `prohibited`.
     */
    public function test_vet_registration_accepts_imaging_when_the_corresponding_equipment_is_declared(): void
    {
        $user = User::factory()->create(['user_type' => 'vet', 'cpf' => null]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-professional', [
            ...$this->vetPayload(),
            'services_offered' => ['consultation', 'imaging'],
            'equipment' => ['ultrasound_machine'],
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('professionals', ['user_id' => $user->id]);
        $professional = Professional::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(['ultrasound_machine'], $professional->equipment);
        $this->assertSame(['consultation', 'imaging'], $professional->services_offered);
    }

    /**
     * Espelho do teste acima: sem nenhum dos três equipamentos de imagem declarados, a API
     * rejeita — é a prova de que a regra de dependência (não só a lista de equipamento
     * permitido) está ativa para `vet`.
     */
    public function test_vet_registration_rejects_imaging_without_any_required_equipment(): void
    {
        $user = User::factory()->create(['user_type' => 'vet', 'cpf' => null]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-professional', [
            ...$this->vetPayload(),
            'services_offered' => ['consultation', 'imaging'],
            'equipment' => ['vascular_doppler'],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['services_offered']);
        $this->assertStringContainsString(
            'Exames de Imagem',
            $response->json('errors.services_offered.0'),
        );
        $this->assertDatabaseMissing('professionals', ['user_id' => $user->id]);
    }

    /** A dupla trava original continua valendo: item fora da lista do tipo é rejeitado. */
    public function test_vet_registration_rejects_equipment_outside_the_allowed_list(): void
    {
        $user = User::factory()->create(['user_type' => 'vet', 'cpf' => null]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-professional', [
            ...$this->vetPayload(),
            'equipment' => ['surgery_room'],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['equipment.0']);
        $this->assertDatabaseMissing('professionals', ['user_id' => $user->id]);
    }

    /**
     * Achado colateral do especialista (§2.2): a mesma regra de dependência já deveria valer
     * para `clinic`/`laboratory` e não valia — clínica podia marcar `imaging` sem ter marcado
     * nenhum equipamento de imagem.
     */
    public function test_clinic_registration_rejects_imaging_without_any_required_equipment(): void
    {
        $user = User::factory()->create(['user_type' => 'clinic']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-professional', [
            ...$this->businessPayload(),
            'technical_responsible_is_self' => true,
            'technical_responsible_crmv' => '12345',
            'technical_responsible_crmv_state' => 'SP',
            'species_served' => ['dog'],
            'sizes_served' => ['medium'],
            'services_offered' => ['consultation', 'imaging'],
            'equipment' => [],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['services_offered']);
        $this->assertDatabaseMissing('professionals', ['user_id' => $user->id]);
    }

    public function test_clinic_registration_accepts_imaging_when_the_corresponding_equipment_is_declared(): void
    {
        $user = User::factory()->create(['user_type' => 'clinic']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-professional', [
            ...$this->businessPayload(),
            'technical_responsible_is_self' => true,
            'technical_responsible_crmv' => '12345',
            'technical_responsible_crmv_state' => 'SP',
            'species_served' => ['dog'],
            'sizes_served' => ['medium'],
            'services_offered' => ['consultation', 'imaging'],
            'equipment' => ['xray_machine'],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('professionals', ['user_id' => $user->id]);
    }

    /**
     * P0 2026-09-13: até esta correção, `services_offered` só aceitava o valor de
     * `ServiceCategory` — o catálogo granular que o front realmente envia (`"deworming"`,
     * `"bath"`, `"xray"`...) tomava 422 em qualquer tipo. `AllowedServiceValue` +
     * `ServiceCatalog::categoryFor()` resolvem o item granular para a categoria antes de
     * conferir contra a matriz — o valor granular é o que fica persistido (busca/exibição).
     */
    public function test_vet_registration_accepts_a_granular_catalog_value_and_persists_it_as_sent(): void
    {
        $user = User::factory()->create(['user_type' => 'vet', 'cpf' => null]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-professional', [
            ...$this->vetPayload(),
            'services_offered' => ['consultation', 'deworming'],
        ]);

        $response->assertOk();

        $professional = Professional::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(['consultation', 'deworming'], $professional->services_offered);
    }

    /**
     * `"deworming"` (vermifugação) resolve para `ServiceCategory::VACCINATION` — permitida
     * para `vet`, proibida para `grooming` (banho e tosa não vacina/vermifuga).
     */
    public function test_grooming_registration_rejects_a_granular_value_whose_category_belongs_to_another_type(): void
    {
        $user = User::factory()->create(['user_type' => 'grooming']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-professional', [
            ...$this->businessPayload(),
            'species_served' => ['dog'],
            'sizes_served' => ['medium'],
            'services_offered' => ['deworming'],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['services_offered.0'])
            ->assertJsonFragment(['services_offered.0' => ['Este serviço não está disponível para o tipo de cadastro selecionado.']]);
    }

    /**
     * `"xray"` (item granular do grupo "Exames Diagnósticos") resolve para
     * `ServiceCategory::IMAGING` — categoria fora da matriz de `grooming`. Mesmo resultado
     * que enviar a categoria `"imaging"` direto: a dupla trava opera sobre a categoria
     * DERIVADA, não sobre a string recebida.
     */
    public function test_grooming_registration_rejects_granular_xray_because_the_derived_category_is_not_allowed(): void
    {
        $user = User::factory()->create(['user_type' => 'grooming']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-professional', [
            ...$this->businessPayload(),
            'species_served' => ['dog'],
            'sizes_served' => ['medium'],
            'services_offered' => ['xray'],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['services_offered.0'])
            ->assertJsonFragment(['services_offered.0' => ['Este serviço não está disponível para o tipo de cadastro selecionado.']]);
        $this->assertDatabaseMissing('professionals', ['user_id' => $user->id]);
    }

    /**
     * `"xray"` resolve para `ServiceCategory::IMAGING`, permitida para `vet` desde
     * `equipamento-vet-volante-e-marketplace-b2b.md` — mas continua exigindo o mesmo
     * equipamento que a categoria `"imaging"` exigiria. Sem isto, quem envia o valor
     * granular escapava da dependência serviço↔equipamento.
     */
    public function test_vet_registration_rejects_granular_xray_without_the_required_equipment(): void
    {
        $user = User::factory()->create(['user_type' => 'vet', 'cpf' => null]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-professional', [
            ...$this->vetPayload(),
            'services_offered' => ['consultation', 'xray'],
            'equipment' => [],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['services_offered']);
        $this->assertDatabaseMissing('professionals', ['user_id' => $user->id]);
    }

    public function test_vet_registration_accepts_granular_xray_when_the_required_equipment_is_declared(): void
    {
        $user = User::factory()->create(['user_type' => 'vet', 'cpf' => null]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-professional', [
            ...$this->vetPayload(),
            'services_offered' => ['consultation', 'xray'],
            'equipment' => ['xray_machine'],
        ]);

        $response->assertOk();

        $professional = Professional::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(['consultation', 'xray'], $professional->services_offered);
        $this->assertSame(['xray_machine'], $professional->equipment);
    }

    /** Valor que não é categoria nem item do catálogo granular: 422 limpo, nunca 500. */
    public function test_registration_rejects_an_unknown_service_value_with_a_clean_422(): void
    {
        $user = User::factory()->create(['user_type' => 'grooming']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-professional', [
            ...$this->businessPayload(),
            'species_served' => ['dog'],
            'sizes_served' => ['medium'],
            'services_offered' => ['this_service_does_not_exist'],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['services_offered.0'])
            ->assertJsonFragment(['services_offered.0' => ['O serviço "this_service_does_not_exist" não é reconhecido pela plataforma.']]);
    }

    /**
     * Dado legado: cadastros existentes gravaram `services_offered` com o valor de
     * `ServiceCategory` (os 9 que já "acertavam por acidente" antes desta correção). Isso
     * precisa continuar válido, inclusive misturado com o valor granular novo no mesmo
     * envio — nenhum dos dois formatos é descartado.
     */
    public function test_legacy_category_value_remains_accepted_alongside_a_granular_value(): void
    {
        $user = User::factory()->create(['user_type' => 'vet', 'cpf' => null]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-professional', [
            ...$this->vetPayload(),
            // 'nutrition' é o valor de CATEGORIA gravado por cadastros antigos;
            // 'behavioral_consultation' é o item GRANULAR do catálogo atual — os dois
            // resolvem para categorias que `vet` permite, e nenhum é normalizado: o array
            // persistido é exatamente o que foi enviado.
            'services_offered' => ['nutrition', 'behavioral_consultation'],
        ]);

        $response->assertOk();

        $professional = Professional::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(['nutrition', 'behavioral_consultation'], $professional->services_offered);
    }

    /** @return array<string, mixed> */
    private function vetPayload(): array
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
            'species_served' => ['dog', 'cat'],
        ];
    }

    /** @return array<string, mixed> */
    private function businessPayload(): array
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
        ];
    }
}
