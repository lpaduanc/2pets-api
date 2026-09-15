<?php

namespace Tests\Unit;

use App\Enums\ProfessionalType;
use App\Enums\ServiceCategory;
use App\Services\Search\SearchQueryInterpreter;
use App\Services\Search\SearchTextNormalizer;
use App\Services\Search\SearchVocabulary;
use PHPUnit\Framework\TestCase;

/**
 * A interpretação do termo é o que decide precisão e recall ANTES de qualquer SQL. Testada
 * sem banco de propósito: nada aqui depende de dado, só do vocabulário versionado.
 */
class SearchQueryInterpreterTest extends TestCase
{
    private SearchQueryInterpreter $interpreter;

    protected function setUp(): void
    {
        parent::setUp();

        $normalizer = new SearchTextNormalizer;
        $this->interpreter = new SearchQueryInterpreter($normalizer, new SearchVocabulary($normalizer));
    }

    /**
     * @return list<string>
     */
    private function needlesOf(string $rawQuery): array
    {
        $needles = [];

        foreach ($this->interpreter->interpret($rawQuery)->units as $unit) {
            $needles = [...$needles, ...$unit->needles];
        }

        return $needles;
    }

    public function test_it_resolves_a_professional_synonym_to_the_canonical_specialty(): void
    {
        $this->assertContains('cardiologia', $this->needlesOf('cardiologista'));
    }

    public function test_it_resolves_an_abbreviation_to_the_canonical_specialty(): void
    {
        $this->assertContains('fisioterapia', $this->needlesOf('fisio'));
    }

    public function test_it_resolves_a_layman_term_to_the_clinical_specialty(): void
    {
        $this->assertContains('odontologia', $this->needlesOf('dentista'));
    }

    public function test_it_ignores_accents_and_plural_when_resolving_a_concept(): void
    {
        $this->assertContains('vacinacao', $this->needlesOf('Vacinações'));
    }

    /**
     * O caso que o dono do produto citou como obrigatório. "cargiolista" não está no
     * vocabulário: só entra via o passo de erro de digitação.
     */
    public function test_it_recovers_a_misspelled_term(): void
    {
        $this->assertContains('cardiologia', $this->needlesOf('cargiolista'));
    }

    /**
     * O limiar de typo é apertado justamente para NÃO inventar conceito. Uma palavra
     * qualquer continua valendo como exigência literal — o que faz a busca devolver vazio em
     * vez de devolver a base inteira.
     */
    public function test_it_keeps_an_unknown_term_as_a_literal_requirement(): void
    {
        $interpreted = $this->interpreter->interpret('xyzabcqwe');

        $this->assertCount(1, $interpreted->units);
        $this->assertSame(['xyzabcqwe'], $interpreted->units[0]->needles);
        $this->assertNull($interpreted->units[0]->concept);
    }

    public function test_it_matches_a_multi_word_alias_before_matching_the_first_word_alone(): void
    {
        $interpreted = $this->interpreter->interpret('banho e tosa');

        $this->assertCount(1, $interpreted->units, 'A janela multi-palavra deve consumir os dois tokens numa unidade só.');
        $this->assertSame(ProfessionalType::GROOMING, $interpreted->units[0]->concept?->professionalType);
        $this->assertSame(ServiceCategory::GROOMING, $interpreted->units[0]->concept?->serviceCategory);
    }

    /**
     * Precisão acima de recall: cada termo significativo vira uma exigência separada, e o
     * SQL as liga com AND. Se a frase virasse uma string única, "clinica veterinaria 24h"
     * traria qualquer clínica.
     */
    public function test_each_significant_term_of_a_phrase_becomes_its_own_requirement(): void
    {
        $interpreted = $this->interpreter->interpret('clinica veterinaria 24h');

        $this->assertCount(2, $interpreted->units);
        $this->assertSame(ProfessionalType::CLINIC, $interpreted->units[0]->concept?->professionalType);
        $this->assertSame(ServiceCategory::EMERGENCY, $interpreted->units[1]->concept?->serviceCategory);
    }

    public function test_connective_words_do_not_become_requirements(): void
    {
        $interpreted = $this->interpreter->interpret('clinica de cardiologia');

        $this->assertCount(2, $interpreted->units, 'O "de" não pode virar exigência.');
    }

    /**
     * Frase só de palavras de ligação não pode virar "busca sem filtro" — isso devolveria
     * todo mundo para quem digitou algo.
     */
    public function test_a_query_made_only_of_connective_words_still_filters(): void
    {
        $interpreted = $this->interpreter->interpret('para o');

        $this->assertFalse($interpreted->isEmpty());
    }

    /**
     * Teto de unidades: a frase enorme não pode fazer o WHERE crescer sem limite.
     */
    public function test_it_caps_the_number_of_requirements(): void
    {
        $interpreted = $this->interpreter->interpret('alfa beta gama delta epsilon zeta eta teta');

        $this->assertLessThanOrEqual(6, count($interpreted->units));
    }

    /**
     * Latência: cada agulha extra multiplica os predicados do WHERE por seis (um por campo).
     * Duas por unidade é o teto — o termo digitado mais UMA forma canônica.
     */
    public function test_a_requirement_never_carries_more_than_two_needles(): void
    {
        foreach (['banho e tosa', 'cardiologista', 'clinica veterinaria', 'fisio'] as $rawQuery) {
            foreach ($this->interpreter->interpret($rawQuery)->units as $unit) {
                $this->assertLessThanOrEqual(2, count($unit->needles), "Termo: {$rawQuery}");
            }
        }
    }
}
