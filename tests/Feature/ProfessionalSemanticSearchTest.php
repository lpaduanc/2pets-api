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
 * Busca por competência: o termo tem que casar contra especialidade, serviço e tipo de
 * negócio — não só contra o nome — e tem que NÃO casar contra nada que o usuário não pediu.
 *
 * Todas as fixtures fixam `business_name`, `description` e `specialties` explicitamente. A
 * `ProfessionalFactory` preenche esses campos com texto aleatório do faker e
 * `specialties => ['general']`, o que tornaria qualquer asserção de "não encontrou"
 * intermitente.
 */
class ProfessionalSemanticSearchTest extends TestCase
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
            'professional_type' => 'vet',
            ...$professionalOverrides,
        ]);

        return $user;
    }

    private function addService(User $professional, string $name, string $category = 'consultation'): void
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
     * @return list<int>
     */
    private function searchIds(array $parameters): array
    {
        $response = $this->getJson('/api/public/search?'.http_build_query($parameters));
        $response->assertOk();

        return array_map(static fn (array $item): int => $item['id'], $response->json('data'));
    }

    // ---------------------------------------------------------------
    // Cobertura semântica
    // ---------------------------------------------------------------

    public function test_it_finds_a_professional_by_declared_specialty(): void
    {
        $specialist = $this->createProfessional('Marcos Lima', ['specialties' => ['Fisioterapia/Reabilitacao']]);
        $other = $this->createProfessional('Renata Souza', ['specialties' => ['Oncologia']]);

        $matched = $this->searchIds(['query' => 'fisioterapia']);

        $this->assertContains($specialist->id, $matched);
        $this->assertNotContains($other->id, $matched);
    }

    /**
     * O caso que motivou a reconstrução: a taxonomia gravada em
     * `professionals.specialties` mistura rótulo, slug e inglês, e a busca precisa casar as
     * três formas sem migration de dados.
     */
    public function test_it_finds_the_same_specialty_written_as_label_slug_or_english(): void
    {
        $label = $this->createProfessional('A Silva', ['specialties' => ['Dermatologia']]);
        $slug = $this->createProfessional('B Silva', ['specialties' => ['dermatologia']]);
        $english = $this->createProfessional('C Silva', ['specialties' => ['dermatology']]);

        $matched = $this->searchIds(['query' => 'dermatologia']);

        $this->assertContains($label->id, $matched);
        $this->assertContains($slug->id, $matched);
        $this->assertContains($english->id, $matched);
    }

    public function test_it_finds_a_professional_by_offered_service_name(): void
    {
        $groomer = $this->createProfessional('Paula Dias');
        $this->addService($groomer, 'Banho e Tosa Higiênica', 'grooming');
        $other = $this->createProfessional('Rafael Nunes');

        $matched = $this->searchIds(['query' => 'tosa']);

        $this->assertContains($groomer->id, $matched);
        $this->assertNotContains($other->id, $matched);
    }

    /**
     * "clínica" tem que encontrar quem É clínica, mesmo sem a palavra em campo nenhum do
     * perfil. É casamento estrutural contra `professional_type`, não texto.
     */
    public function test_it_finds_a_clinic_by_the_portuguese_label_of_its_type(): void
    {
        $clinic = $this->createProfessional('Bom Amigo', [
            'professional_type' => 'clinic',
            'business_name' => 'Bom Amigo Saúde Animal',
        ]);
        $petshop = $this->createProfessional('Loja do Bicho', ['professional_type' => 'petshop']);

        $matched = $this->searchIds(['query' => 'clinica']);

        $this->assertContains($clinic->id, $matched);
        $this->assertNotContains($petshop->id, $matched);
    }

    // ---------------------------------------------------------------
    // Tolerância a digitação e acento
    // ---------------------------------------------------------------

    public function test_it_finds_a_specialty_typed_with_a_typo(): void
    {
        $cardiologist = $this->createProfessional('Ana Prado', ['specialties' => ['Cardiologia']]);

        $this->assertContains($cardiologist->id, $this->searchIds(['query' => 'cargiolista']));
    }

    public function test_accented_and_unaccented_terms_return_the_same_professional(): void
    {
        $professional = $this->createProfessional('Helena Braga', ['specialties' => ['Nutricao Animal']]);
        $this->addService($professional, 'Vacinação Anual');

        $this->assertContains($professional->id, $this->searchIds(['query' => 'vacinação']));
        $this->assertContains($professional->id, $this->searchIds(['query' => 'vacinacao']));
    }

    // ---------------------------------------------------------------
    // Precisão — o que NÃO pode entrar
    // ---------------------------------------------------------------

    public function test_a_term_without_any_correspondence_returns_nothing(): void
    {
        $this->createProfessional('Joana Pires', ['specialties' => ['Cardiologia']]);

        $this->assertSame([], $this->searchIds(['query' => 'xyzabcqwe']));
    }

    /**
     * Especialidades de nome parecido não podem se confundir: "neurologia" e "nefrologia"
     * ficam a uma letra de distância e são áreas diferentes.
     */
    public function test_a_similar_but_different_specialty_is_not_a_match(): void
    {
        $nephrologist = $this->createProfessional('Sergio Maia', ['specialties' => ['Nefrologia/Urologia']]);

        $this->assertNotContains($nephrologist->id, $this->searchIds(['query' => 'neurologia']));
    }

    /**
     * Termo multi-palavra é E, não OU: quem atende só um dos dois critérios fica de fora.
     */
    public function test_every_significant_term_of_a_phrase_must_match(): void
    {
        $onlyClinic = $this->createProfessional('Vida Pet', [
            'professional_type' => 'clinic',
            'business_name' => 'Clínica Vida Pet',
        ]);
        $clinicWithCardiology = $this->createProfessional('Coração Pet', [
            'professional_type' => 'clinic',
            'business_name' => 'Clínica Coração Pet',
            'specialties' => ['Cardiologia'],
        ]);

        $matched = $this->searchIds(['query' => 'clinica cardiologia']);

        $this->assertContains($clinicWithCardiology->id, $matched);
        $this->assertNotContains($onlyClinic->id, $matched);
    }

    // ---------------------------------------------------------------
    // Ordenação
    // ---------------------------------------------------------------

    /**
     * Com termo, ordenar por distância enterra o resultado certo — o padrão passa a ser
     * relevância.
     */
    /**
     * Com termo, ordenar por distância enterra o resultado certo — o padrão passa a ser
     * relevância, e a evidência dura tem que vir na frente.
     *
     * ⚠️ Mudou em 2026-09-14: antes, quem só CITAVA cardiologia na descrição entrava no
     * resultado (no fim da lista). Agora não entra: "cardiologia" é termo conceitual e passou
     * a exigir evidência de competência (especialidade declarada ou serviço com aquele nome).
     * Descrição continua filtrando para termo DESCONHECIDO — ver
     * `test_an_unknown_term_still_matches_free_text`.
     */
    public function test_relevance_is_the_default_sort_when_a_term_is_given(): void
    {
        $byDescription = $this->createProfessional('Tiago Rocha', [
            'description' => 'Atendemos também casos de cardiologia quando necessário.',
        ]);
        // Nome neutro de propósito: a relevância desta linha tem que vir da ESPECIALIDADE,
        // não de um casamento acidental contra `users.name`.
        $bySpecialty = $this->createProfessional('Beatriz Moura', ['specialties' => ['Cardiologia']]);
        $byServiceName = $this->createProfessional('Marcos Vieira');
        $this->addService($byServiceName, 'Consulta de Cardiologia');

        $matched = $this->searchIds(['query' => 'cardiologia']);

        $this->assertNotContains($byDescription->id, $matched, 'Descrição não qualifica termo conceitual.');
        $this->assertSame($byServiceName->id, $matched[0], 'Nome de serviço é a evidência mais forte.');
        $this->assertContains($bySpecialty->id, $matched);
    }

    // ---------------------------------------------------------------
    // Filtros novos
    // ---------------------------------------------------------------

    public function test_specialty_filter_matches_label_and_slug_alike(): void
    {
        $label = $this->createProfessional('D Silva', ['specialties' => ['Cardiologia']]);
        $slug = $this->createProfessional('E Silva', ['specialties' => ['cardiologia', 'clinica_geral']]);
        $other = $this->createProfessional('F Silva', ['specialties' => ['Ortopedia']]);

        foreach (['Cardiologia', 'cardiologia', 'cardiologista'] as $filterValue) {
            $matched = $this->searchIds(['specialty' => $filterValue]);

            $this->assertContains($label->id, $matched, "Filtro: {$filterValue}");
            $this->assertContains($slug->id, $matched, "Filtro: {$filterValue}");
            $this->assertNotContains($other->id, $matched, "Filtro: {$filterValue}");
        }
    }

    /**
     * Semântica estrita: espécie não declarada é "não informou", nunca "atende tudo".
     */
    public function test_species_filter_only_returns_who_declared_the_species(): void
    {
        $declared = $this->createProfessional('G Silva', ['species_served' => ['dog', 'cat']]);
        $otherSpecies = $this->createProfessional('H Silva', ['species_served' => ['bird']]);
        $undeclared = $this->createProfessional('I Silva', ['species_served' => null]);

        $matched = $this->searchIds(['species' => 'dog']);

        $this->assertContains($declared->id, $matched);
        $this->assertNotContains($otherSpecies->id, $matched);
        $this->assertNotContains($undeclared->id, $matched);
    }

    /**
     * A chave do erro é `species.0`, e não `species`: desde a multi-seleção o filtro é
     * sempre uma lista (o escalar legado é embrulhado em `PublicProfessionalSearchRequest`),
     * então o erro pertence ao ITEM. É a forma padrão do Laravel para validação de array.
     */
    public function test_species_outside_the_taxonomy_is_rejected(): void
    {
        $this->getJson('/api/public/search?species=dinossauro')
            ->assertStatus(422)
            ->assertJsonValidationErrors('species.0');
    }

    public function test_a_blank_filter_is_treated_as_no_filter(): void
    {
        $professional = $this->createProfessional('J Silva', ['specialties' => ['Ortopedia']]);

        $this->assertContains($professional->id, $this->searchIds(['specialty' => '']));
    }

    // ---------------------------------------------------------------
    // Contrato do card
    // ---------------------------------------------------------------

    /**
     * O card de listagem não carrega `services[]` nem o bloco `professional{}` duplicado —
     * eram 81% do payload. Se voltarem, este teste falha antes de a regressão chegar ao app.
     */
    public function test_the_search_card_does_not_carry_the_full_service_list(): void
    {
        $professional = $this->createProfessional('K Silva');
        $this->addService($professional, 'Consulta Geral');

        $card = $this->getJson('/api/public/search')->assertOk()->json('data.0');

        $this->assertArrayNotHasKey('services', $card);
        $this->assertArrayNotHasKey('professional', $card);
        $this->assertArrayNotHasKey('email', $card);
        $this->assertArrayNotHasKey('phone', $card);
        $this->assertSame(100.0, $card['starting_price']);
    }

    // ---------------------------------------------------------------
    // Qualificação: tipo ranqueia, nunca qualifica sozinho
    // ---------------------------------------------------------------

    /**
     * O DEFEITO QUE ORIGINOU ESTA CORREÇÃO, reproduzido literalmente: um estabelecimento
     * cadastrado como banho e tosa cujos serviços reais são clínica geral e vacinação
     * aparecia na busca por "banho e tosa" — porque o `professional_type` bastava para
     * qualificar. Agora não basta.
     */
    public function test_business_type_alone_does_not_qualify_a_conceptual_term(): void
    {
        $mislabeled = $this->createProfessional('Hospital Veterinario Freitas', [
            'professional_type' => 'grooming',
            'business_name' => 'Hospital Veterinario Freitas',
        ]);
        $this->addService($mislabeled, 'Clinica Geral', 'consultation');
        $this->addService($mislabeled, 'Vacinacao', 'vaccination');

        $realGroomer = $this->createProfessional('Espaco Pet', ['professional_type' => 'grooming']);
        $this->addService($realGroomer, 'Banho', 'grooming');

        $matched = $this->searchIds(['query' => 'banho e tosa']);

        $this->assertContains($realGroomer->id, $matched);
        $this->assertNotContains($mislabeled->id, $matched);
    }

    /**
     * A contrapartida do teste acima: o mesmo cadastro continua encontrável pelo que
     * realmente faz e pelo nome. A regra remove falso-positivo, não remove o profissional da
     * plataforma.
     */
    public function test_the_same_professional_is_still_found_by_what_it_actually_offers(): void
    {
        $mislabeled = $this->createProfessional('Hospital Veterinario Freitas', [
            'professional_type' => 'grooming',
            'business_name' => 'Hospital Veterinario Freitas',
        ]);
        $this->addService($mislabeled, 'Vacinacao', 'vaccination');

        $this->assertContains($mislabeled->id, $this->searchIds(['query' => 'vacinacao']));
        $this->assertContains($mislabeled->id, $this->searchIds(['query' => 'Freitas']));
    }

    /**
     * A exceção deliberada: "clínica" é conceito que corresponde SÓ a um tipo de negócio —
     * não existe serviço chamado "ser uma clínica". Ali o tipo é a evidência mais dura
     * possível e continua qualificando, senão o termo ficaria inbuscável.
     */
    public function test_a_pure_business_type_concept_still_qualifies_by_type(): void
    {
        $clinic = $this->createProfessional('Bom Amigo', ['professional_type' => 'clinic']);
        $groomer = $this->createProfessional('Studio Pet', ['professional_type' => 'grooming']);

        $matched = $this->searchIds(['query' => 'clinica']);

        $this->assertContains($clinic->id, $matched);
        $this->assertNotContains($groomer->id, $matched);
    }

    /**
     * O tipo perdeu o direito de qualificar, mas não o de RANQUEAR. Entre dois
     * estabelecimentos que ambos oferecem banho e tosa, o que se cadastrou COMO banho e tosa
     * sobe — é o bônus de tipo em `ProfessionalRelevanceRanking::applyTo()`.
     */
    public function test_business_type_breaks_the_tie_between_equal_evidence(): void
    {
        $petshop = $this->createProfessional('Casa Pet', ['professional_type' => 'petshop']);
        $this->addService($petshop, 'Banho', 'grooming');

        $groomer = $this->createProfessional('Studio Pet', ['professional_type' => 'grooming']);
        $this->addService($groomer, 'Banho', 'grooming');

        $matched = $this->searchIds(['query' => 'banho e tosa']);

        $this->assertSame($groomer->id, $matched[0]);
        $this->assertContains($petshop->id, $matched);
    }

    /**
     * A decisão de produto sobre profissional recém-cadastrado: ele NÃO some da busca
     * conceitual enquanto não criar serviços, porque o cadastro obriga a declarar
     * `services_offered` de forma granular. Ele aparece — por último, com o peso da camada
     * estrutural em vez do peso da evidência dura.
     */
    public function test_a_newly_registered_professional_appears_by_declared_offering(): void
    {
        $withServices = $this->createProfessional('Studio Pet', ['professional_type' => 'grooming']);
        $this->addService($withServices, 'Banho', 'grooming');

        $onlyDeclared = $this->createProfessional('Recanto Novo', [
            'professional_type' => 'grooming',
            'services_offered' => ['bath', 'haircut'],
        ]);

        $matched = $this->searchIds(['query' => 'banho e tosa']);

        $this->assertContains($onlyDeclared->id, $matched);
        $this->assertSame($withServices->id, $matched[0], 'Serviço cadastrado ranqueia acima de oferta só declarada.');
    }

    /**
     * Termo desconhecido continua com busca aberta — inclusive na descrição, que é a camada
     * mais fraca. Buscar por nome próprio ou por palavra fora do vocabulário é uso legítimo.
     */
    public function test_an_unknown_term_still_matches_free_text(): void
    {
        $professional = $this->createProfessional('Deluxe Pet Care', [
            'description' => 'Atendimento premium com transporte incluso.',
        ]);

        $this->assertContains($professional->id, $this->searchIds(['query' => 'Deluxe']));
        $this->assertContains($professional->id, $this->searchIds(['query' => 'premium']));
    }

    // ---------------------------------------------------------------
    // Prefixo: como as pessoas digitam de verdade
    // ---------------------------------------------------------------

    /**
     * O bug mais importante reportado pelo dono do produto: "cardio" não encontrava
     * Cardiologia. O passo de typo não resolvia (`levenshtein` = 5, acima do teto de 3);
     * prefixo precisa de critério próprio.
     *
     * @dataProvider specialtyPrefixProvider
     */
    public function test_a_prefix_resolves_to_the_canonical_concept(string $prefix, string $specialty): void
    {
        $specialist = $this->createProfessional('Nome Neutro', ['specialties' => [$specialty]]);

        $this->assertContains(
            $specialist->id,
            $this->searchIds(['query' => $prefix]),
            "'{$prefix}' tem que encontrar '{$specialty}'.",
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function specialtyPrefixProvider(): array
    {
        return [
            'cardio' => ['cardio', 'Cardiologia'],
            'derma' => ['derma', 'Dermatologia'],
            'oftalmo' => ['oftalmo', 'Oftalmologia'],
            'neuro' => ['neuro', 'Neurologia'],
            'gastro' => ['gastro', 'Gastroenterologia'],
        ];
    }

    /**
     * A guarda de ambiguidade: prefixo que serve a mais de um conceito não resolve para
     * nenhum — escolher um seria decidir pelo usuário com base na ordem do array. "medi" é
     * prefixo de "medicina felina" E de "medicina de animais silvestres exoticos".
     */
    public function test_an_ambiguous_prefix_does_not_resolve_to_a_single_concept(): void
    {
        $feline = $this->createProfessional('Gato Bom', ['specialties' => ['Medicina Felina']]);
        $exotic = $this->createProfessional('Bicho Raro', ['specialties' => ['Medicina de Animais Silvestres/Exoticos']]);

        $matched = $this->searchIds(['query' => 'medicina']);

        $this->assertContains($feline->id, $matched);
        $this->assertContains($exotic->id, $matched);
    }

    /**
     * Termo curto (3 letras) é tratado como PREFIXO DE PALAVRA, não trigrama solto. "car"
     * casa "Care" e "Carlos"; não casa "Oscar" nem "Descarte". O dono do produto observou que
     * "car" trazendo "Deluxe Pet Care" não é erro — o que faltava era o comportamento ser
     * previsível em vez de casual.
     */
    public function test_a_short_term_matches_a_word_prefix_and_not_the_middle_of_a_word(): void
    {
        $prefix = $this->createProfessional('Deluxe Pet Care');
        $middle = $this->createProfessional('Oscar Delgado');

        $matched = $this->searchIds(['query' => 'car']);

        $this->assertContains($prefix->id, $matched);
        $this->assertNotContains($middle->id, $matched);
    }

    // ---------------------------------------------------------------
    // Forma do SQL — guarda de performance
    // ---------------------------------------------------------------

    /**
     * Este teste existe por causa de um incidente medido: com CADA filtro de `professionals`
     * em sua própria subquery, o Postgres encadeia um semi-join por filtro e, com a
     * cardinalidade externa subestimada pelo `ST_DWithin`, o último recai em `Seq Scan` —
     * "termo + tipo + nota + geo" levava 42,8 s contra 137 ms com os filtros juntos.
     *
     * Cada filtro isolado sempre esteve rápido e correto, então nenhum teste de comportamento
     * pega essa regressão. O que a pega é travar a FORMA: uma única subquery contra
     * `professionals` para todos os filtros estruturais.
     *
     * A versão MULTIVALORADA da mesma guarda (a forma em que é mais fácil escorregar para
     * uma subquery por valor) vive em `ProfessionalSearchMultiFilterTest`.
     */
    public function test_professional_level_filters_share_a_single_subquery(): void
    {
        $filters = SearchFiltersDTO::fromRequest([
            'professional_type' => 'vet',
            'min_rating' => 4,
            'specialty' => 'cardiologia',
            'species' => 'dog',
            'latitude' => -23.55,
            'longitude' => -46.63,
        ]);

        $sql = $this->buildFilteredQuerySql($filters);

        $this->assertSame(
            1,
            substr_count($sql, 'select "professionals"."user_id" from "professionals"'),
            'Os filtros de `professionals` têm que caber numa subquery só — ver o docblock de '
            .'`ProfessionalSearchService::applyProfessionalFilters()`.'
        );
    }

    /**
     * Mesma guarda para `services`: categoria e faixa de preço numa subquery só.
     */
    public function test_service_level_filters_share_a_single_subquery(): void
    {
        $filters = SearchFiltersDTO::fromRequest([
            'service_category' => 'grooming',
            'min_price' => 50,
            'max_price' => 200,
        ]);

        $sql = $this->buildFilteredQuerySql($filters);

        $this->assertSame(1, substr_count($sql, 'select "services"."professional_id" from "services"'));
    }

    private function buildFilteredQuerySql(SearchFiltersDTO $filters): string
    {
        $service = app(ProfessionalSearchService::class);
        $buildFilteredQuery = new ReflectionMethod($service, 'buildFilteredQuery');

        return $buildFilteredQuery->invoke($service, $filters, 'users.id')->toSql();
    }
}
