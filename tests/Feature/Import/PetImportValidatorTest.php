<?php

namespace Tests\Feature\Import;

use App\Models\Breed;
use App\Models\DataImport;
use App\Models\ProfessionalClient;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Import\BrazilianFormatParser;
use App\Services\Import\BreedMatcher;
use App\Services\Import\CatalogSimilarityMatcher;
use App\Services\Import\ClientDuplicateDetector;
use App\Services\Import\CoatCatalogResolver;
use App\Services\Import\PetImportValidator;
use App\Services\Import\PetSpeciesSynonymResolver;
use App\Services\Import\PetTutorResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Item 26 do backlog gap-simplesvet — "vinculado a cliente já importado/existente" é
 * bloqueante desta linha (regra 3); espécie/raça nunca bloqueiam (regra 4).
 */
class PetImportValidatorTest extends TestCase
{
    use RefreshDatabase;

    private PetImportValidator $validator;

    private User $professional;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new PetImportValidator(
            new BrazilianFormatParser,
            new PetSpeciesSynonymResolver,
            new BreedMatcher(new CatalogSimilarityMatcher),
            new CoatCatalogResolver(new CatalogSimilarityMatcher, new CommercialScopeResolver),
            new PetTutorResolver(new ClientDuplicateDetector),
        );
        $this->professional = User::factory()->professional()->create();
    }

    private function importFor(User $professional): DataImport
    {
        return DataImport::create([
            'professional_id' => $professional->id,
            'user_id' => $professional->id,
            'entity' => 'pets',
            'file_path' => 'data-imports/fake.csv',
            'status' => 'validating',
            'batch_uuid' => (string) Str::uuid(),
        ]);
    }

    public function test_pet_of_an_already_linked_client_is_valid(): void
    {
        $client = User::factory()->tutor()->create(['cpf' => '12345678909']);
        ProfessionalClient::create(['professional_id' => $this->professional->id, 'client_id' => $client->id]);

        $result = $this->validator->validate([
            'name' => 'Rex',
            'species' => 'cachorro',
            'tutor_cpf' => '12345678909',
        ], $this->importFor($this->professional));

        $this->assertTrue($result['valid']);
        $this->assertSame($client->id, $result['normalized']['tutor_user_id']);
        $this->assertSame('dog', $result['normalized']['species']);
    }

    public function test_pet_without_a_matching_tutor_is_invalid(): void
    {
        $result = $this->validator->validate([
            'name' => 'Rex',
            'species' => 'cachorro',
            'tutor_cpf' => '00000000000',
        ], $this->importFor($this->professional));

        $this->assertFalse($result['valid']);
        $this->assertContains(
            'Tutor não encontrado para os dados informados. Importe clientes antes de pets.',
            $result['errors'],
        );
    }

    public function test_unrecognized_species_never_blocks_and_falls_back_to_other(): void
    {
        $client = User::factory()->tutor()->create(['email' => 'tutor@example.com']);
        ProfessionalClient::create(['professional_id' => $this->professional->id, 'client_id' => $client->id]);

        $result = $this->validator->validate([
            'name' => 'Nina',
            'species' => 'Chinchila',
            'tutor_email' => 'tutor@example.com',
        ], $this->importFor($this->professional));

        $this->assertTrue($result['valid']);
        $this->assertSame('other', $result['normalized']['species']);
    }

    public function test_breed_matches_existing_catalog_by_similarity(): void
    {
        Breed::factory()->dog()->create(['name' => 'Labrador Retriever']);
        $client = User::factory()->tutor()->create(['email' => 'tutor2@example.com']);
        ProfessionalClient::create(['professional_id' => $this->professional->id, 'client_id' => $client->id]);

        $result = $this->validator->validate([
            'name' => 'Bidu',
            'species' => 'cachorro',
            'breed' => 'labrador retriver',
            'tutor_email' => 'tutor2@example.com',
        ], $this->importFor($this->professional));

        $this->assertTrue($result['valid']);
        $this->assertSame('Labrador Retriever', $result['normalized']['breed']);
        $this->assertNotNull($result['normalized']['breed_id']);
    }

    public function test_unmatched_breed_is_kept_as_free_text_without_blocking(): void
    {
        $client = User::factory()->tutor()->create(['email' => 'tutor3@example.com']);
        ProfessionalClient::create(['professional_id' => $this->professional->id, 'client_id' => $client->id]);

        $result = $this->validator->validate([
            'name' => 'Bidu',
            'species' => 'cachorro',
            'breed' => 'Vira-lata caramelo',
            'tutor_email' => 'tutor3@example.com',
        ], $this->importFor($this->professional));

        $this->assertTrue($result['valid']);
        $this->assertSame('Vira-lata caramelo', $result['normalized']['breed']);
        $this->assertNull($result['normalized']['breed_id']);
    }

    public function test_missing_pet_name_is_invalid(): void
    {
        $result = $this->validator->validate(['species' => 'cachorro'], $this->importFor($this->professional));

        $this->assertFalse($result['valid']);
        $this->assertContains('Nome do pet é obrigatório.', $result['errors']);
    }
}
