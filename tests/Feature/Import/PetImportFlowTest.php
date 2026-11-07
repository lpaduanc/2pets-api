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
        $this->assertSame(ImportStatus::COMPLETED, $import->fresh()->status);
    }

    /**
     * O desfazer de pets passou a existir (`ImportRollbackService::rollbackPet`): o teste antigo
     * esperava recusa total, de quando o pet não tinha rollback. Agora: pet intocado sai; pet com
     * ato clínico depois da importação é recusado com motivo.
     */
    public function test_rollback_of_a_pet_import_removes_untouched_pets_and_refuses_pets_with_clinical_activity(): void
    {
        $vet = User::factory()->professional()->create();
        $client = User::factory()->tutor()->create();
        $untouched = Pet::factory()->create(['user_id' => $client->id]);
        $treated = Pet::factory()->create(['user_id' => $client->id]);
        \App\Models\Appointment::create([
            'pet_id' => $treated->id, 'client_id' => $client->id, 'professional_id' => $vet->id,
            'appointment_date' => now()->toDateString(), 'appointment_time' => '10:00', 'type' => 'consultation', 'status' => 'scheduled',
        ]);

        $import = DataImport::create([
            'professional_id' => $vet->id,
            'user_id' => $vet->id,
            'entity' => 'pets',
            'file_path' => 'data-imports/fake.csv',
            'status' => ImportStatus::COMPLETED->value,
            'batch_uuid' => (string) Str::uuid(),
        ]);

        foreach ([$untouched, $treated] as $index => $pet) {
            $import->rows()->create([
                'row_number' => $index + 1,
                'raw' => ['nome' => $pet->name],
                'status' => ImportRowStatus::IMPORTED->value,
                'created_record_type' => Pet::class,
                'created_record_id' => $pet->id,
            ]);
        }

        $response = $this->actingAs($vet)->postJson("/api/data-imports/{$import->id}/rollback");

        $response->assertOk();
        $response->assertJsonPath('rolled_back', 1);
        $this->assertCount(1, $response->json('refused'));
        $this->assertNull(Pet::find($untouched->id));
        $this->assertNotNull(Pet::find($treated->id));
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
