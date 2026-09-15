<?php

namespace Tests\Feature;

use App\DataTransferObjects\SearchFiltersDTO;
use App\Models\Professional;
use App\Models\Service;
use App\Models\User;
use App\Services\Search\ProfessionalSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Multi-seleção dos quatro filtros da busca pública: `professional_type`,
 * `service_category`, `specialty` e `species`.
 *
 * ── Contrato de fio (acordado com o frontend) ─────────────────────────────────────────
 * Cada dimensão aceita as DUAS formas:
 *   - escalar legado: `?professional_type=vet` — é o que o `2pets-site` manda hoje e não
 *     pode quebrar;
 *   - array:          `?professional_type[]=vet&professional_type[]=clinic`.
 * Semântica: **OR dentro da mesma dimensão, AND entre dimensões.**
 *
 * Filtro é restrição DURA em AND por cima do ranking existente — nunca um empurrão no
 * score. A hierarquia de relevância (`ProfessionalRelevanceRanking`/`RelevanceTierBuilder`)
 * não participa da decisão de quem entra.
 */
class ProfessionalSearchMultiFilterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $professionalOverrides
     */
    private function createProfessional(string $name, array $professionalOverrides = []): User
    {
        $user = User::factory()->professional()->create([
            'name' => $name,
            'profile_completed' => true,
            'registration_status' => 'approved',
            'is_suspended' => false,
        ]);

        Professional::factory()->create([
            'user_id' => $user->id,
            'business_name' => null,
            'description' => null,
            'specialties' => [],
            'species_served' => null,
            'professional_type' => 'vet',
            ...$professionalOverrides,
        ]);

        return $user;
    }

    private function addService(User $professional, string $name, string $category): void
    {
        Service::create([
            'professional_id' => $professional->id,
            'name' => $name,
            'category' => $category,
            'duration' => 30,
            'price' => 100,
            'active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return list<int>
     */
    private function searchIds(array $parameters): array
    {
        $response = $this->getJson('/api/public/search?'.http_build_query($parameters));
        $response->assertOk();

        return array_map(static fn (array $item): int => $item['id'], $response->json('data'));
    }

    // ---------------------------------------------------------------
    // OR dentro da dimensão
    // ---------------------------------------------------------------

    public function test_professional_type_accepts_a_list_and_returns_the_union(): void
    {
        $vet = $this->createProfessional('Vet Um', ['professional_type' => 'vet']);
        $clinic = $this->createProfessional('Clinica Dois', ['professional_type' => 'clinic']);
        $grooming = $this->createProfessional('Tosa Tres', ['professional_type' => 'grooming']);

        $matched = $this->searchIds(['professional_type' => ['vet', 'clinic']]);

        $this->assertContains($vet->id, $matched);
        $this->assertContains($clinic->id, $matched);
        $this->assertNotContains($grooming->id, $matched);
    }

    public function test_species_accepts_a_list_and_returns_the_union(): void
    {
        $canine = $this->createProfessional('So Cao', ['species_served' => ['dog']]);
        $feline = $this->createProfessional('So Gato', ['species_served' => ['cat']]);
        $exotic = $this->createProfessional('So Ave', ['species_served' => ['bird']]);

        $matched = $this->searchIds(['species' => ['dog', 'cat']]);

        $this->assertContains($canine->id, $matched);
        $this->assertContains($feline->id, $matched);
        $this->assertNotContains($exotic->id, $matched);
    }

    public function test_specialty_accepts_a_list_of_free_text_terms(): void
    {
        $cardiologist = $this->createProfessional('Cardio Um', ['specialties' => ['Cardiologia']]);
        $orthopedist = $this->createProfessional('Orto Dois', ['specialties' => ['Ortopedia']]);
        $dermatologist = $this->createProfessional('Derma Tres', ['specialties' => ['Dermatologia']]);

        $matched = $this->searchIds(['specialty' => ['Cardiologia', 'ortopedia']]);

        $this->assertContains($cardiologist->id, $matched);
        $this->assertContains($orthopedist->id, $matched);
        $this->assertNotContains($dermatologist->id, $matched);
    }

    public function test_service_category_accepts_a_list_and_returns_the_union(): void
    {
        $groomer = $this->createProfessional('Banho Um', ['professional_type' => 'grooming']);
        $surgeon = $this->createProfessional('Cirurgia Dois');
        $vaccinator = $this->createProfessional('Vacina Tres');

        $this->addService($groomer, 'Banho', 'grooming');
        $this->addService($surgeon, 'Castracao', 'surgery');
        $this->addService($vaccinator, 'V10', 'vaccination');

        $matched = $this->searchIds(['service_category' => ['grooming', 'surgery']]);

        $this->assertContains($groomer->id, $matched);
        $this->assertContains($surgeon->id, $matched);
        $this->assertNotContains($vaccinator->id, $matched);
    }

    // ---------------------------------------------------------------
    // AND entre dimensões
    // ---------------------------------------------------------------

    /**
     * A combinação é a parte que erra fácil: cada dimensão sozinha traz o profissional,
     * e ainda assim ele tem que sair quando a OUTRA dimensão não bate.
     */
    public function test_dimensions_combine_with_and_not_or(): void
    {
        $both = $this->createProfessional('Casa Os Dois', [
            'professional_type' => 'vet',
            'species_served' => ['cat'],
        ]);
        $onlyType = $this->createProfessional('So O Tipo', [
            'professional_type' => 'vet',
            'species_served' => ['bird'],
        ]);
        $onlySpecies = $this->createProfessional('So A Especie', [
            'professional_type' => 'grooming',
            'species_served' => ['cat'],
        ]);

        $matched = $this->searchIds([
            'professional_type' => ['vet', 'clinic'],
            'species' => ['cat', 'dog'],
        ]);

        $this->assertContains($both->id, $matched);
        $this->assertNotContains($onlyType->id, $matched);
        $this->assertNotContains($onlySpecies->id, $matched);
    }

    // ---------------------------------------------------------------
    // Compatibilidade com a forma escalar
    // ---------------------------------------------------------------

    /**
     * O `2pets-site` manda escalar hoje. Quebrar isso derruba a busca do site inteiro sem
     * um erro sequer no backend — a lista voltaria diferente, não vazia.
     */
    public function test_the_legacy_scalar_form_keeps_working(): void
    {
        $vet = $this->createProfessional('Vet Escalar', ['professional_type' => 'vet']);
        $groomer = $this->createProfessional('Tosa Escalar', ['professional_type' => 'grooming']);

        $matched = $this->searchIds(['professional_type' => 'vet']);

        $this->assertContains($vet->id, $matched);
        $this->assertNotContains($groomer->id, $matched);
    }

    public function test_a_scalar_and_a_single_item_list_produce_the_same_result(): void
    {
        $this->createProfessional('Vet Comparado', ['professional_type' => 'vet']);
        $this->createProfessional('Tosa Comparada', ['professional_type' => 'grooming']);

        $this->assertSame(
            $this->searchIds(['professional_type' => 'vet']),
            $this->searchIds(['professional_type' => ['vet']]),
        );
    }

    // ---------------------------------------------------------------
    // Validação
    // ---------------------------------------------------------------

    /**
     * Valor fora da taxonomia devolvia lista vazia sem explicar. 422 diz ao cliente que ele
     * errou, em vez de deixá-lo achar que não existe profissional daquele tipo.
     *
     * A chave do erro é `professional_type.0` (forma padrão do Laravel para item de array),
     * não `professional_type`.
     */
    public function test_a_professional_type_outside_the_taxonomy_is_rejected(): void
    {
        $this->getJson('/api/public/search?professional_type=veterinario_volante')
            ->assertStatus(422)
            ->assertJsonValidationErrors('professional_type.0');
    }

    public function test_a_service_category_outside_the_taxonomy_is_rejected(): void
    {
        $this->getJson('/api/public/search?service_category=banho_e_tosa')
            ->assertStatus(422)
            ->assertJsonValidationErrors('service_category.0');
    }

    public function test_one_invalid_item_rejects_the_whole_list(): void
    {
        $this->getJson('/api/public/search?'.http_build_query(['species' => ['dog', 'dinossauro']]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('species.1');
    }

    /**
     * Cada especialidade extra multiplica os predicados do OR; sem teto, uma URL montada à
     * mão vira um WHERE que nenhum índice poda.
     */
    public function test_a_list_longer_than_the_cap_is_rejected(): void
    {
        $tooMany = array_fill(0, 11, 'cardiologia');

        $this->getJson('/api/public/search?'.http_build_query(['specialty' => $tooMany]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('specialty');
    }

    /**
     * ⚠️ `?species=` chega ao Form Request como `null`, não como `''` — o middleware global
     * `ConvertEmptyStringsToNull` já converteu. Testar só a string vazia NÃO pega o caso, e
     * o sintoma seria 422 para quem simplesmente não escolheu nada no `<select>`.
     */
    public function test_a_blank_value_in_a_closed_taxonomy_is_treated_as_no_filter(): void
    {
        $professional = $this->createProfessional('Sem Filtro', ['species_served' => ['dog']]);

        $this->assertContains($professional->id, $this->searchIds(['species' => '']));
        $this->assertContains($professional->id, $this->searchIds(['professional_type' => '']));
    }

    public function test_a_blank_item_inside_a_list_is_discarded_instead_of_rejected(): void
    {
        $feline = $this->createProfessional('Gateiro', ['species_served' => ['cat']]);
        $canine = $this->createProfessional('Cachorreiro', ['species_served' => ['dog']]);

        $matched = $this->searchIds(['species' => ['', 'cat']]);

        $this->assertContains($feline->id, $matched);
        $this->assertNotContains($canine->id, $matched);
    }

    // ---------------------------------------------------------------
    // Forma canônica da lista (chave de cache)
    // ---------------------------------------------------------------

    /**
     * OR é comutativo: `[vet,clinic]` e `[clinic,vet]` são a MESMA busca. Sem forma
     * canônica no DTO, `ProfessionalSearchCache` geraria duas entradas para a mesma consulta
     * e o acerto cairia pela metade a cada valor extra marcado no filtro — degradação de
     * performance que nenhum teste de resultado pega, porque o resultado continua correto.
     */
    public function test_value_order_does_not_change_the_filter(): void
    {
        $ascending = SearchFiltersDTO::fromRequest(['professional_type' => ['vet', 'clinic']]);
        $descending = SearchFiltersDTO::fromRequest(['professional_type' => ['clinic', 'vet']]);

        $this->assertSame($ascending->professionalTypes, $descending->professionalTypes);
    }

    public function test_repeated_values_are_deduplicated(): void
    {
        $filters = SearchFiltersDTO::fromRequest(['species' => ['dog', 'dog', 'cat']]);

        $this->assertSame(['cat', 'dog'], $filters->species);
    }

    public function test_an_absent_filter_is_an_empty_list_not_null(): void
    {
        $filters = SearchFiltersDTO::fromRequest([]);

        $this->assertSame([], $filters->professionalTypes);
        $this->assertSame([], $filters->serviceCategories);
        $this->assertSame([], $filters->specialties);
        $this->assertSame([], $filters->species);
    }

    // ---------------------------------------------------------------
    // Forma do SQL com multi-seleção
    // ---------------------------------------------------------------

    /**
     * A guarda de forma que mais importa nesta mudança. Tornar as dimensões multivaloradas
     * é EXATAMENTE o momento em que se escreve, sem querer, um `whereIn`/subquery por VALOR:
     * a forma parece limpa, cada filtro isolado continua rápido, e a combinação
     * "termo + tipo + nota + geo" volta a levar 42,8 s (medido) contra 137 ms com tudo numa
     * subquery só. Nenhum teste de resultado pega isso — a asserção precisa ser sobre a
     * ESTRUTURA da query.
     */
    public function test_multi_valued_professional_filters_still_share_a_single_subquery(): void
    {
        $filters = SearchFiltersDTO::fromRequest([
            'professional_type' => ['vet', 'clinic', 'grooming'],
            'specialty' => ['cardiologia', 'dermatologia'],
            'species' => ['dog', 'cat'],
            'min_rating' => 4,
            'latitude' => -23.55,
            'longitude' => -46.63,
        ]);

        $this->assertSame(
            1,
            substr_count($this->buildFilteredQuerySql($filters), 'select "professionals"."user_id" from "professionals"'),
            'Multi-seleção não pode virar uma subquery por valor — ver '
            .'`ProfessionalSearchService::applyProfessionalFilters()`.'
        );
    }

    public function test_multi_valued_service_filters_still_share_a_single_subquery(): void
    {
        $filters = SearchFiltersDTO::fromRequest([
            'service_category' => ['grooming', 'surgery', 'consultation'],
            'min_price' => 50,
            'max_price' => 200,
        ]);

        $this->assertSame(
            1,
            substr_count($this->buildFilteredQuerySql($filters), 'select "services"."professional_id" from "services"'),
        );
    }

    /**
     * Espécie multivalorada resolve num operador só (`jsonb_exists_any`), não num predicado
     * por espécie — e nunca com o operador `?|`, cujo `?` colide com o placeholder de
     * binding do PDO.
     */
    public function test_multi_valued_species_resolves_in_a_single_predicate(): void
    {
        $filters = SearchFiltersDTO::fromRequest(['species' => ['dog', 'cat', 'bird']]);

        $sql = $this->buildFilteredQuerySql($filters);

        $this->assertSame(1, substr_count($sql, 'jsonb_exists_any'));
        $this->assertStringNotContainsString('?|', $sql);
    }

    private function buildFilteredQuerySql(SearchFiltersDTO $filters): string
    {
        $service = app(ProfessionalSearchService::class);
        $buildFilteredQuery = new ReflectionMethod($service, 'buildFilteredQuery');

        return $buildFilteredQuery->invoke($service, $filters, 'users.id')->toSql();
    }
}
