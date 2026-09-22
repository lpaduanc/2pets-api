<?php

namespace Tests\Unit\Import;

use App\Enums\PetSpecies;
use App\Services\Import\PetSpeciesSynonymResolver;
use Tests\TestCase;

/**
 * Item 26 do backlog gap-simplesvet — sinônimos PT-BR de espécie (cão/cachorro/canino,
 * gato/felino) casam com `PetSpecies`; o que não bate nunca bloqueia a linha (regra 4), cai
 * em `PetSpecies::OTHER`.
 */
class PetSpeciesSynonymResolverTest extends TestCase
{
    private PetSpeciesSynonymResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new PetSpeciesSynonymResolver;
    }

    /**
     * @dataProvider dogSynonymsProvider
     */
    public function test_dog_synonyms_resolve_to_dog_species(string $synonym): void
    {
        $this->assertSame(PetSpecies::DOG, $this->resolver->resolve($synonym));
    }

    /**
     * @return list<list<string>>
     */
    public static function dogSynonymsProvider(): array
    {
        return [['Cachorro'], ['cão'], ['CANINO'], ['canina'], ['Cadela']];
    }

    /**
     * @dataProvider catSynonymsProvider
     */
    public function test_cat_synonyms_resolve_to_cat_species(string $synonym): void
    {
        $this->assertSame(PetSpecies::CAT, $this->resolver->resolve($synonym));
    }

    /**
     * @return list<list<string>>
     */
    public static function catSynonymsProvider(): array
    {
        return [['Gato'], ['felino'], ['Felina']];
    }

    public function test_unrecognized_species_falls_back_to_other_without_error(): void
    {
        $this->assertSame(PetSpecies::OTHER, $this->resolver->resolve('Chinchila'));
    }

    public function test_was_recognized_distinguishes_real_synonym_from_fallback(): void
    {
        $this->assertTrue($this->resolver->wasRecognized('cachorro'));
        $this->assertFalse($this->resolver->wasRecognized('chinchila'));
    }
}
