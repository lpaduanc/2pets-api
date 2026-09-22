<?php

namespace Tests\Feature\Import;

use App\Enums\Import\ImportRowStatus;
use App\Enums\Import\ImportStatus;
use App\Models\DataImport;
use App\Models\Pet;
use App\Models\User;
use App\Models\Vaccination;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Item 26 do backlog gap-simplesvet — fluxo ponta a ponta de importação de `vaccinations`.
 * `professional_id` fica nulo (autoria clínica não pode ser fabricada, ver
 * `ImportedVaccinationProvisioner`).
 */
class VaccinationImportFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_importing_vaccinations_before_any_pet_exists_is_blocked(): void
    {
        $vet = User::factory()->professional()->create();
        $file = UploadedFile::fake()->createWithContent('vacinas.csv', "vacina,data_aplicacao\nV10,05/03/2026\n");

        $response = $this->actingAs($vet)->post('/api/data-imports', [
            'entity' => 'vaccinations',
            'file' => $file,
        ], ['Accept' => 'application/json']);

        $response->assertStatus(422);
        $this->assertStringContainsString('Importe pets primeiro', (string) $response->getContent());
    }

    public function test_vaccination_import_execution_creates_dose_without_fabricating_authorship(): void
    {
        $vet = User::factory()->professional()->create();
        $client = User::factory()->tutor()->create();
        $pet = Pet::factory()->create(['user_id' => $client->id, 'name' => 'Thor']);

        $import = $this->readyImportFor($vet, [
            'pet_id' => $pet->id,
            'vaccine_name' => 'V10',
            'manufacturer' => null,
            'batch_number' => null,
            'application_date' => '2026-03-05',
            'expiry_date' => null,
            'next_dose_date' => null,
            'dose_number' => 1,
            'notes' => null,
        ]);

        $this->actingAs($vet)
            ->postJson("/api/data-imports/{$import->id}/execute", ['duplicate_strategy' => 'skip'])
            ->assertStatus(202);

        $vaccination = Vaccination::where('pet_id', $pet->id)->first();
        $this->assertNotNull($vaccination);
        $this->assertNull($vaccination->professional_id);
        $this->assertSame(ImportStatus::COMPLETED->value, $import->fresh()->status);
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function readyImportFor(User $professional, array $normalized): DataImport
    {
        $import = DataImport::create([
            'professional_id' => $professional->id,
            'user_id' => $professional->id,
            'entity' => 'vaccinations',
            'file_path' => 'data-imports/fake.csv',
            'status' => ImportStatus::READY->value,
            'valid_rows' => 1,
            'batch_uuid' => (string) Str::uuid(),
        ]);

        $import->rows()->create([
            'row_number' => 1,
            'raw' => ['vacina' => $normalized['vaccine_name']],
            'normalized' => $normalized,
            'status' => ImportRowStatus::VALID->value,
        ]);

        return $import;
    }
}
