<?php

namespace Tests\Unit;

use App\Enums\ProfessionalType;
use Database\Seeders\Dataset\DatasetRandom;
use Database\Seeders\Dataset\SpeciesCoverage;
use PHPUnit\Framework\TestCase;

/**
 * `species_served` do dataset de desenvolvimento precisa ser DERIVADO da oferta, não
 * sorteado à parte.
 *
 * O defeito original era invisível em teste de busca: a busca por espécie estava certa, mas
 * 7.000 de 7.000 cadastros declaravam `dog`, então `?species=dog` devolvia o catálogo
 * inteiro e o filtro não excluía ninguém. O que prova a correção não é "o filtro funciona"
 * (sempre funcionou) e sim "os conjuntos são SEPARÁVEIS".
 */
class SpeciesCoverageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Semente fixa: `SpeciesCoverage` usa sorteio para as fatias minoritárias, e sem
        // isto a asserção de distribuição seria intermitente.
        DatasetRandom::seed(20260914);
    }

    /**
     * O caso concreto relatado pelo dono do produto: um `vet` cuja única especialidade é
     * Medicina Felina declarando que atende cães.
     */
    public function test_a_professional_whose_only_specialty_is_feline_medicine_does_not_serve_dogs(): void
    {
        $species = SpeciesCoverage::planFor(ProfessionalType::VET, ['Medicina Felina']);

        $this->assertSame(['cat'], $species);
    }

    public function test_feline_medicine_alongside_another_specialty_keeps_dogs(): void
    {
        $species = SpeciesCoverage::planFor(ProfessionalType::VET, ['Medicina Felina', 'Dermatologia']);

        $this->assertContains('dog', $species);
        $this->assertContains('cat', $species);
    }

    public function test_the_exotic_specialty_brings_exotic_species(): void
    {
        $species = SpeciesCoverage::planFor(
            ProfessionalType::CLINIC,
            ['Clinica Geral', 'Medicina de Animais Silvestres/Exoticos'],
        );

        $this->assertNotEmpty(array_intersect(['bird', 'rodent', 'reptile'], $species));
    }

    /**
     * Exóticos como ÚNICA especialidade = cadastro exclusivamente exótico. Sem essa minoria,
     * todo exótico viria acompanhado de cão e `?species=bird` nunca excluiria um canino.
     */
    public function test_the_exotic_specialty_alone_excludes_dog_and_cat(): void
    {
        $species = SpeciesCoverage::planFor(
            ProfessionalType::VET,
            ['Medicina de Animais Silvestres/Exoticos'],
        );

        $this->assertNotContains('dog', $species);
        $this->assertNotContains('cat', $species);
        $this->assertNotEmpty($species);
    }

    /**
     * Adestramento é prática majoritariamente canina — e é a maior fonte de cadastros
     * dog-only, o que torna `?species=cat` mensuravelmente menor que `?species=dog`.
     */
    public function test_training_is_predominantly_canine(): void
    {
        $withCat = 0;

        for ($attempt = 0; $attempt < 200; $attempt++) {
            $species = SpeciesCoverage::planFor(ProfessionalType::TRAINING, []);

            $this->assertContains('dog', $species);
            $withCat += in_array('cat', $species, true) ? 1 : 0;
        }

        $this->assertGreaterThan(0, $withCat, 'Adestrador que atende gato existe.');
        $this->assertLessThan(100, $withCat, 'Mas é minoria — senão dog e cat voltam a coincidir.');
    }

    /**
     * A asserção que realmente importa para o produto: cão e gato não podem coincidir, e
     * nenhum dos dois pode cobrir o catálogo inteiro. É a condição que o dataset anterior
     * violava (7.000 de 7.000 com `dog`).
     */
    public function test_the_distribution_is_separable_across_the_seven_types(): void
    {
        $counts = ['dog' => 0, 'cat' => 0, 'exotic' => 0];
        $total = 0;

        foreach (ProfessionalType::cases() as $type) {
            for ($attempt = 0; $attempt < 100; $attempt++) {
                $species = SpeciesCoverage::planFor($type, $this->specialtiesFor($attempt));
                $total++;
                $counts['dog'] += in_array('dog', $species, true) ? 1 : 0;
                $counts['cat'] += in_array('cat', $species, true) ? 1 : 0;
                $counts['exotic'] += array_intersect(['bird', 'rodent', 'reptile'], $species) === [] ? 0 : 1;
            }
        }

        $this->assertLessThan($total, $counts['dog'], 'Cão não pode cobrir o catálogo inteiro.');
        $this->assertLessThan($total, $counts['cat']);
        $this->assertNotSame($counts['dog'], $counts['cat'], 'Cão e gato precisam separar conjuntos diferentes.');
        $this->assertGreaterThan(0, $counts['exotic']);
        $this->assertLessThan($counts['cat'], $counts['exotic'], 'Exótico é nicho, não maioria.');
    }

    /**
     * Especialidades plausíveis, alternando o recorte felino/exótico para exercitar os três
     * caminhos de `planFor()` em vez de só o padrão.
     *
     * @return list<string>
     */
    private function specialtiesFor(int $attempt): array
    {
        return match ($attempt % 4) {
            0 => ['Medicina Felina'],
            1 => ['Medicina de Animais Silvestres/Exoticos'],
            2 => ['Clinica Geral', 'Cardiologia'],
            default => [],
        };
    }
}
