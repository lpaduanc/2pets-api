<?php

namespace Tests\Unit;

use App\Enums\ProfessionalType;
use Database\Seeders\Dataset\BusinessNamePools;
use Database\Seeders\Dataset\DatasetRandom;
use Database\Seeders\Dataset\ProfessionalNaming;
use PHPUnit\Framework\TestCase;

/**
 * O pronome de tratamento do veterinário volante precisa concordar com o primeiro nome.
 *
 * Sorteados de forma independente, o dataset gerava "Dra. Vinícius Lima", "Dra. Otávio Melo"
 * e "Dra. Igor Dias" — defeito pequeno, mas que aparece na cara do usuário em toda busca por
 * nome, que é a primeira coisa que ele testa.
 */
class ProfessionalNamingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DatasetRandom::seed(20260914);
    }

    public function test_the_title_always_agrees_with_the_first_name(): void
    {
        for ($attempt = 0; $attempt < 300; $attempt++) {
            $name = ProfessionalNaming::displayNameFor(ProfessionalType::VET, null);
            [$title, $firstName] = explode(' ', $name);

            $expected = $title === 'Dr.'
                ? BusinessNamePools::MASCULINE_FIRST_NAMES
                : BusinessNamePools::FEMININE_FIRST_NAMES;

            $this->assertContains($firstName, $expected, "Concordância quebrada em: {$name}");
        }
    }

    public function test_both_titles_are_produced(): void
    {
        $titles = [];

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $titles[] = explode(' ', ProfessionalNaming::displayNameFor(ProfessionalType::VET, null))[0];
        }

        $this->assertContains('Dr.', $titles);
        $this->assertContains('Dra.', $titles);
    }

    /**
     * `vet` é pessoa física (CPF + CRMV): não tem nome fantasia. Os outros seis tipos são PJ
     * e o nome exibido É o nome fantasia — senão o card mostraria o nome do sócio.
     */
    public function test_a_business_type_shows_the_business_name_without_any_title(): void
    {
        $name = ProfessionalNaming::displayNameFor(ProfessionalType::CLINIC, 'Clínica Veterinária Aurora');

        $this->assertSame('Clínica Veterinária Aurora', $name);
    }

    public function test_the_union_of_first_names_covers_both_genders(): void
    {
        $this->assertSame(
            count(BusinessNamePools::FEMININE_FIRST_NAMES) + count(BusinessNamePools::MASCULINE_FIRST_NAMES),
            count(BusinessNamePools::FIRST_NAMES),
        );
    }
}
