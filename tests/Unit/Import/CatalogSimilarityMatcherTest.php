<?php

namespace Tests\Unit\Import;

use App\Services\Import\CatalogSimilarityMatcher;
use Tests\TestCase;

/**
 * Item 26 do backlog gap-simplesvet — casamento por similaridade em PHP (`similar_text()`),
 * usado por `BreedMatcher`/`CoatCatalogResolver` para casar raça/pelagem digitadas com o
 * catálogo já cadastrado.
 */
class CatalogSimilarityMatcherTest extends TestCase
{
    private CatalogSimilarityMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = new CatalogSimilarityMatcher;
    }

    public function test_close_misspelling_matches_the_closest_candidate(): void
    {
        $match = $this->matcher->bestMatch('labrador retriver', ['Labrador Retriever', 'Poodle', 'Beagle']);

        $this->assertSame('Labrador Retriever', $match);
    }

    public function test_unrelated_text_does_not_match_anything(): void
    {
        $match = $this->matcher->bestMatch('Chinchila', ['Labrador Retriever', 'Poodle', 'Beagle']);

        $this->assertNull($match);
    }

    public function test_empty_needle_never_matches(): void
    {
        $this->assertNull($this->matcher->bestMatch('', ['Poodle']));
    }

    public function test_empty_candidate_list_never_matches(): void
    {
        $this->assertNull($this->matcher->bestMatch('Poodle', []));
    }
}
