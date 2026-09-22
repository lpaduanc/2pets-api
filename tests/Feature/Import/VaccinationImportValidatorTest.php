<?php

namespace Tests\Feature\Import;

use App\Enums\ImmunizationGroup;
use App\Enums\PetSpecies;
use App\Models\DataImport;
use App\Models\ImmunizationProduct;
use App\Models\Pet;
use App\Models\ProfessionalClient;
use App\Models\User;
use App\Services\Import\BrazilianFormatParser;
use App\Services\Import\CatalogSimilarityMatcher;
use App\Services\Import\ClientDuplicateDetector;
use App\Services\Import\PetDuplicateDetector;
use App\Services\Import\PetTutorResolver;
use App\Services\Import\VaccinationImportValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Item 26 do backlog gap-simplesvet — vacina exige pet já importado/existente do tutor
 * informado (regra 3); data de aplicação é obrigatória e lida no formato `dd/mm/aaaa`.
 */
class VaccinationImportValidatorTest extends TestCase
{
    use RefreshDatabase;

    private VaccinationImportValidator $validator;

    private User $professional;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new VaccinationImportValidator(
            new BrazilianFormatParser,
            new PetTutorResolver(new ClientDuplicateDetector),
            new PetDuplicateDetector,
            new CatalogSimilarityMatcher,
        );
        $this->professional = User::factory()->professional()->create();
    }

    private function importFor(User $professional): DataImport
    {
        return DataImport::create([
            'professional_id' => $professional->id,
            'user_id' => $professional->id,
            'entity' => 'vaccinations',
            'file_path' => 'data-imports/fake.csv',
            'status' => 'validating',
            'batch_uuid' => (string) Str::uuid(),
        ]);
    }

    public function test_vaccination_of_an_existing_pet_is_valid(): void
    {
        $client = User::factory()->tutor()->create(['cpf' => '11122233344']);
        ProfessionalClient::create(['professional_id' => $this->professional->id, 'client_id' => $client->id]);
        $pet = Pet::factory()->create(['user_id' => $client->id, 'name' => 'Thor']);

        $result = $this->validator->validate([
            'tutor_cpf' => '11122233344',
            'pet_name' => 'thor',
            'vaccine_name' => 'V10',
            'application_date' => '05/03/2026',
        ], $this->importFor($this->professional));

        $this->assertTrue($result['valid']);
        $this->assertSame($pet->id, $result['normalized']['pet_id']);
        $this->assertSame('2026-03-05', $result['normalized']['application_date']);
    }

    public function test_vaccination_without_a_matching_pet_is_invalid(): void
    {
        $client = User::factory()->tutor()->create(['cpf' => '99988877766']);
        ProfessionalClient::create(['professional_id' => $this->professional->id, 'client_id' => $client->id]);

        $result = $this->validator->validate([
            'tutor_cpf' => '99988877766',
            'pet_name' => 'Pet Inexistente',
            'vaccine_name' => 'V10',
            'application_date' => '05/03/2026',
        ], $this->importFor($this->professional));

        $this->assertFalse($result['valid']);
        $this->assertContains('Pet não encontrado para o tutor informado. Importe pets primeiro.', $result['errors']);
    }

    public function test_missing_application_date_is_invalid(): void
    {
        $result = $this->validator->validate([
            'vaccine_name' => 'V10',
        ], $this->importFor($this->professional));

        $this->assertFalse($result['valid']);
        $this->assertContains('Data de aplicação é obrigatória.', $result['errors']);
    }

    /**
     * Item 26 migrado de `VaccineCatalog` (legado) para `ImmunizationProduct` como fonte da
     * correção ortográfica por similaridade — contrato
     * docs/gap-simplesvet/contratos/13-contrato-api.md.
     */
    public function test_vaccine_name_is_corrected_by_similarity_with_the_global_immunization_catalog(): void
    {
        $vaccine = ImmunizationProduct::create([
            'organization_id' => null,
            'name' => 'V10',
            'group' => ImmunizationGroup::VACCINE,
            'legally_required' => false,
            'active' => true,
        ]);
        $vaccine->speciesLinks()->create(['species' => PetSpecies::DOG]);

        $client = User::factory()->tutor()->create(['cpf' => '11122233355']);
        ProfessionalClient::create(['professional_id' => $this->professional->id, 'client_id' => $client->id]);
        Pet::factory()->create(['user_id' => $client->id, 'name' => 'Thor', 'species' => 'dog']);

        $result = $this->validator->validate([
            'tutor_cpf' => '11122233355',
            'pet_name' => 'thor',
            'vaccine_name' => 'v-10',
            'application_date' => '05/03/2026',
        ], $this->importFor($this->professional));

        $this->assertTrue($result['valid']);
        $this->assertSame('V10', $result['normalized']['vaccine_name']);
    }
}
