<?php

namespace Tests\Unit;

use App\Services\Search\SearchTextNormalizer;
use App\Services\Search\SearchVocabulary;
use App\Support\Search\SearchConceptDefinitions;
use PHPUnit\Framework\TestCase;

/**
 * Invariantes do vocabulário. São testes de DADO, não de comportamento: protegem quem for
 * adicionar um sinônimo novo de quebrar a busca sem perceber.
 */
class SearchVocabularyTest extends TestCase
{
    /**
     * As 20 linhas da tabela `specialties` (`database/seeders/SpecialtySeeder.php`), como
     * estão gravadas — sem acento. Ficam aqui como literal em vez de virem do banco porque
     * este é um teste unitário: o ponto é justamente falhar quando alguém adicionar uma
     * especialidade ao catálogo e esquecer de ensinar a busca a reconhecê-la.
     *
     * @return list<string>
     */
    private function catalogSpecialties(): array
    {
        return [
            'Anestesiologia', 'Cardiologia', 'Cirurgia Geral', 'Comportamento Animal',
            'Dermatologia', 'Diagnostico por Imagem', 'Endocrinologia',
            'Fisioterapia/Reabilitacao', 'Gastroenterologia',
            'Medicina de Animais Silvestres/Exoticos', 'Medicina Felina',
            'Nefrologia/Urologia', 'Neurologia', 'Nutricao Animal',
            'Odontologia Veterinaria', 'Oftalmologia', 'Oncologia', 'Ortopedia',
            'Patologia Clinica', 'Reproducao Animal',
        ];
    }

    private function vocabulary(): SearchVocabulary
    {
        return new SearchVocabulary(new SearchTextNormalizer);
    }

    public function test_every_catalog_specialty_is_recognized_by_the_vocabulary(): void
    {
        $vocabulary = $this->vocabulary();

        foreach ($this->catalogSpecialties() as $specialty) {
            $this->assertNotNull(
                $vocabulary->conceptFor($specialty),
                "A especialidade \"{$specialty}\" existe no catálogo e não tem conceito de busca."
            );
        }
    }

    /**
     * A busca escolhe UM conceito por termo. Dois conceitos com o mesmo alias fariam o
     * resultado depender da ordem do array de definições — um bug que não dá erro nenhum,
     * só uma busca que às vezes acerta.
     */
    public function test_no_alias_belongs_to_two_different_concepts(): void
    {
        $normalizer = new SearchTextNormalizer;
        $owners = [];

        foreach (SearchConceptDefinitions::all() as $definition) {
            foreach ($definition['aliases'] as $alias) {
                $key = $normalizer->canonicalKey($alias);
                $owners[$key][] = $definition['key'];
            }
        }

        foreach ($owners as $alias => $conceptKeys) {
            $this->assertCount(
                1,
                array_unique($conceptKeys),
                "O alias \"{$alias}\" pertence a mais de um conceito: ".implode(', ', array_unique($conceptKeys))
            );
        }
    }

    /**
     * `terms` alimenta o filtro exato `?specialty=`, que compara palavra inteira contra o
     * texto gravado. Um term com acento ou maiúscula nunca casaria, e o sintoma seria um
     * filtro que devolve zero sem erro nenhum.
     */
    public function test_canonical_terms_are_written_already_normalized(): void
    {
        $normalizer = new SearchTextNormalizer;

        foreach (SearchConceptDefinitions::all() as $definition) {
            foreach ($definition['terms'] as $term) {
                $this->assertSame(
                    $normalizer->normalize($term),
                    $term,
                    "O termo canônico \"{$term}\" do conceito \"{$definition['key']}\" não está normalizado."
                );
            }
        }
    }

    public function test_specialty_filter_accepts_label_slug_and_synonym_alike(): void
    {
        $vocabulary = $this->vocabulary();

        $fromLabel = $vocabulary->storedFormsFor('Cardiologia');
        $fromSlug = $vocabulary->storedFormsFor('cardiologia');
        $fromSynonym = $vocabulary->storedFormsFor('cardiologista');

        $this->assertSame($fromLabel, $fromSlug);
        $this->assertSame($fromLabel, $fromSynonym);
        $this->assertContains('cardiologia', $fromLabel);
    }

    /**
     * Especialidade fora do vocabulário não pode virar "sem filtro": isso devolveria a base
     * inteira para quem pediu uma especialidade específica.
     */
    public function test_an_unknown_specialty_filter_falls_back_to_the_literal_value(): void
    {
        $this->assertSame(['acupuntura quantica'], $this->vocabulary()->storedFormsFor('Acupuntura Quântica'));
    }
}
