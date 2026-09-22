<?php

namespace Tests\Feature\Import;

use App\Enums\Import\ImportRowStatus;
use App\Enums\Import\ImportStatus;
use App\Models\DataImport;
use App\Models\Pet;
use App\Models\ProfessionalClient;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Item 26 do backlog gap-simplesvet — fluxo ponta a ponta de importação de `pets` via HTTP:
 * bloqueio de ordem, upload, execução e vínculo com o tutor já cadastrado.
 */
class PetImportFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_uploading_pets_before_any_client_exists_is_blocked(): void
    {
        $vet = User::factory()->professional()->create();
        $file = UploadedFile::fake()->createWithContent('pets.csv', "nome,especie\nRex,cachorro\n");

        $response = $this->actingAs($vet)->post('/api/data-imports', [
            'entity' => 'pets',
            'file' => $file,
        ], ['Accept' => 'application/json']);

        $response->assertStatus(422);
        $response->assertJsonPath('entity', 'pets');
        $this->assertStringContainsString('Importe clientes primeiro', (string) $response->getContent());
    }

    public function test_pet_import_execution_creates_pet_linked_to_existing_tutor(): void
    {
        $vet = User::factory()->professional()->create();
        $client = User::factory()->tutor()->create(['cpf' => '12345678909']);
        ProfessionalClient::create(['professional_id' => $vet->id, 'client_id' => $client->id]);

        $import = $this->readyImportFor($vet, [
            'tutor_user_id' => $client->id,
            'name' => 'Rex',
            'species' => 'dog',
            'breed' => null,
            'breed_id' => null,
            'coat_colors' => null,
            'gender' => 'male',
            'weight' => null,
            'birth_date' => null,
            'neutered' => null,
            'microchip_number' => null,
        ]);

        $this->actingAs($vet)
            ->postJson("/api/data-imports/{$import->id}/execute", ['duplicate_strategy' => 'skip'])
            ->assertStatus(202);

        $pet = Pet::where('name', 'Rex')->first();
        $this->assertNotNull($pet);
        $this->assertSame($client->id, $pet->user_id);
        $this->assertSame(ImportStatus::COMPLETED->value, $import->fresh()->status);
    }

    public function test_rollback_of_a_pet_import_is_refused_not_silently_reported_as_success(): void
    {
        $vet = User::factory()->professional()->create();
        $client = User::factory()->tutor()->create();
        $pet = Pet::factory()->create(['user_id' => $client->id]);

        $import = DataImport::create([
            'professional_id' => $vet->id,
            'user_id' => $vet->id,
            'entity' => 'pets',
            'file_path' => 'data-imports/fake.csv',
            'status' => ImportStatus::COMPLETED->value,
            'batch_uuid' => (string) Str::uuid(),
        ]);
        $import->rows()->create([
            'row_number' => 1,
            'raw' => ['nome' => $pet->name],
            'status' => ImportRowStatus::IMPORTED->value,
            'created_record_type' => Pet::class,
            'created_record_id' => $pet->id,
        ]);

        $response = $this->actingAs($vet)->postJson("/api/data-imports/{$import->id}/rollback");

        $response->assertOk();
        $response->assertJsonPath('rolled_back', 0);
        $this->assertCount(1, $response->json('refused'));
        $this->assertNotNull(Pet::find($pet->id));
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function readyImportFor(User $professional, array $normalized): DataImport
    {
        $import = DataImport::create([
            'professional_id' => $professional->id,
            'user_id' => $professional->id,
            'entity' => 'pets',
            'file_path' => 'data-imports/fake.csv',
            'status' => ImportStatus::READY->value,
            'valid_rows' => 1,
            'batch_uuid' => (string) Str::uuid(),
        ]);

        $import->rows()->create([
            'row_number' => 1,
            'raw' => ['nome' => $normalized['name']],
            'normalized' => $normalized,
            'status' => ImportRowStatus::VALID->value,
        ]);

        return $import;
    }
}
