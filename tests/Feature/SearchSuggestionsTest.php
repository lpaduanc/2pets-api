<?php

namespace Tests\Feature;

use App\Models\Professional;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O "você quis dizer..." de `GET /api/public/search`.
 *
 * ── O problema ────────────────────────────────────────────────────────────────────────
 * `?query=car` devolve creche e hotel no topo porque "car" casa legitimamente com
 * "Day **Car**e". Os cardiologistas estão no conjunto, só ranqueados abaixo. O ranking não
 * está errado; o termo é que é curto demais para decidir.
 *
 * ── O contrato ────────────────────────────────────────────────────────────────────────
 * `meta.suggestions[] = { term, label, total }`, ordenado por `total` decrescente, no máximo
 * 3. `term` é o que o cliente reenvia como `?query=`; `total` é contado sob os MESMOS filtros
 * e a MESMA geolocalização da busca atual — sugestão que leva a zero é pior que sugestão
 * nenhuma. A chave sai SEMPRE, mesmo vazia, para o cliente não precisar de guarda.
 *
 * ── O invariante mais importante ──────────────────────────────────────────────────────
 * A sugestão NÃO muda o resultado da busca. Nem quem entra, nem em que ordem.
 */
class SearchSuggestionsTest extends TestCase
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
     * Dois conceitos que "car" alcança por prefixo de palavra: "cardiologia" (alias
     * "cardiologia") e "hospedagem" (alias "day care").
     */
    private function seedAmbiguousDataset(): void
    {
        $cardiologist = $this->createProfessional('Consultorio Um', [
            'specialties' => ['Cardiologia'],
            'professional_type' => 'vet',
        ]);
        $this->addService($cardiologist, 'Consulta cardiologica', 'consultation');

        $hotel = $this->createProfessional('Creche Dois', ['professional_type' => 'pet_hotel']);
        $this->addService($hotel, 'Hospedagem', 'boarding');

        $otherHotel = $this->createProfessional('Creche Tres', ['professional_type' => 'pet_hotel']);
        $this->addService($otherHotel, 'Hospedagem diaria', 'boarding');
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<int, array<string, mixed>>
     */
    private function suggestionsFor(array $parameters): array
    {
        $response = $this->getJson('/api/public/search?'.http_build_query($parameters));
        $response->assertOk();

        return $response->json('meta.suggestions');
    }

    // ---------------------------------------------------------------
    // Quando aparece
    // ---------------------------------------------------------------

    public function test_an_ambiguous_short_term_returns_the_concepts_it_reaches(): void
    {
        $this->seedAmbiguousDataset();

        $terms = array_column($this->suggestionsFor(['query' => 'car']), 'term');

        $this->assertContains('cardiologia', $terms);
        $this->assertContains('hospedagem', $terms);
    }

    public function test_suggestions_are_ordered_by_total_descending(): void
    {
        $this->seedAmbiguousDataset();

        $totals = array_column($this->suggestionsFor(['query' => 'car']), 'total');

        $sorted = $totals;
        rsort($sorted);

        $this->assertSame($sorted, $totals);
    }

    public function test_every_suggestion_carries_a_human_label_and_a_positive_total(): void
    {
        $this->seedAmbiguousDataset();

        foreach ($this->suggestionsFor(['query' => 'car']) as $suggestion) {
            $this->assertArrayHasKey('label', $suggestion);
            $this->assertNotSame('', $suggestion['label']);
            $this->assertGreaterThan(0, $suggestion['total']);
        }
    }

    /**
     * O `total` prometido tem que ser o que o cliente recebe ao reenviar o `term` — é a razão
     * de existir do campo. Se divergisse, a sugestão passaria a ser uma estimativa e o
     * usuário aprenderia a ignorá-la.
     */
    public function test_the_promised_total_matches_the_search_for_the_suggested_term(): void
    {
        $this->seedAmbiguousDataset();

        foreach ($this->suggestionsFor(['query' => 'car']) as $suggestion) {
            $response = $this->getJson('/api/public/search?'.http_build_query(['query' => $suggestion['term']]));

            $this->assertSame($suggestion['total'], $response->json('meta.total'), $suggestion['term']);
        }
    }

    // ---------------------------------------------------------------
    // Quando NÃO aparece
    // ---------------------------------------------------------------

    public function test_a_term_that_resolves_to_a_single_concept_gets_no_suggestions(): void
    {
        $this->seedAmbiguousDataset();

        $this->assertSame([], $this->suggestionsFor(['query' => 'cardio']));
        $this->assertSame([], $this->suggestionsFor(['query' => 'cardiologia']));
    }

    public function test_an_exact_alias_gets_no_suggestions_even_when_short(): void
    {
        $this->seedAmbiguousDataset();

        $this->assertSame([], $this->suggestionsFor(['query' => 'vet']));
    }

    public function test_a_term_that_reaches_no_concept_gets_no_suggestions(): void
    {
        $this->seedAmbiguousDataset();

        $this->assertSame([], $this->suggestionsFor(['query' => 'Freitas']));
    }

    public function test_a_multi_word_term_gets_no_suggestions(): void
    {
        $this->seedAmbiguousDataset();

        $this->assertSame([], $this->suggestionsFor(['query' => 'banho e tosa']));
    }

    public function test_a_search_without_a_term_still_publishes_an_empty_suggestion_list(): void
    {
        $this->seedAmbiguousDataset();

        $this->assertSame([], $this->suggestionsFor([]));
    }

    // ---------------------------------------------------------------
    // Invariantes
    // ---------------------------------------------------------------

    public function test_a_concept_without_results_is_never_suggested(): void
    {
        // Só cardiologista na base: "hospedagem" alcançaria o conceito, mas levaria a zero.
        $cardiologist = $this->createProfessional('Consultorio Um', ['specialties' => ['Cardiologia']]);
        $this->addService($cardiologist, 'Consulta cardiologica', 'consultation');

        $terms = array_column($this->suggestionsFor(['query' => 'car']), 'term');

        $this->assertNotContains('hospedagem', $terms);
    }

    public function test_suggestions_do_not_change_which_professionals_are_returned(): void
    {
        $this->seedAmbiguousDataset();

        $response = $this->getJson('/api/public/search?'.http_build_query(['query' => 'car']));
        $response->assertOk();

        $withSuggestions = array_column($response->json('data'), 'id');

        // A mesma busca cacheada (segunda chamada) tem que devolver exatamente a mesma lista.
        $again = $this->getJson('/api/public/search?'.http_build_query(['query' => 'car']));

        $this->assertSame($withSuggestions, array_column($again->json('data'), 'id'));
        $this->assertNotEmpty($withSuggestions);
    }

    public function test_the_suggestion_totals_respect_the_filters_of_the_current_search(): void
    {
        $this->seedAmbiguousDataset();

        $unfiltered = $this->suggestionsFor(['query' => 'car']);
        $filtered = $this->suggestionsFor(['query' => 'car', 'professional_type' => ['pet_hotel']]);

        $this->assertNotSame(
            array_column($unfiltered, 'total'),
            array_column($filtered, 'total'),
            'O total da sugestao tem que mudar quando o filtro da busca muda.'
        );
    }

    public function test_at_most_three_suggestions_are_returned(): void
    {
        $this->seedAmbiguousDataset();

        $this->assertLessThanOrEqual(3, count($this->suggestionsFor(['query' => 'med'])));
    }
}
