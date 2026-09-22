<?php

namespace Tests\Feature\Import;

use App\Enums\Import\ImportRowStatus;
use App\Enums\Import\ImportStatus;
use App\Jobs\ImportBatchJob;
use App\Models\ConsentLog;
use App\Models\DataImport;
use App\Models\Pet;
use App\Models\ProfessionalClient;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Item 26 do backlog gap-simplesvet — fluxo ponta a ponta de `data-imports` via HTTP:
 * permissão de rota, upload, mapeamento sugerido, execução assíncrona e desfazer.
 */
class DataImportFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_tutor_without_data_import_permission_is_forbidden(): void
    {
        $tutor = User::factory()->tutor()->create();

        $this->actingAs($tutor)
            ->postJson('/api/data-imports', ['entity' => 'clients'])
            ->assertForbidden();
    }

    public function test_vet_freelancer_uploads_csv_and_receives_suggested_mapping(): void
    {
        $vet = User::factory()->professional()->create();
        $file = UploadedFile::fake()->createWithContent('clientes.csv', "nome,cpf,email,telefone\nJoão Silva,12345678909,joao@example.com,11999998888\n");

        $response = $this->actingAs($vet)->post('/api/data-imports', [
            'entity' => 'clients',
            'file' => $file,
        ], ['Accept' => 'application/json']);

        $response->assertCreated();
        $response->assertJsonPath('data.entity', 'clients');
        $response->assertJsonPath('data.status', 'uploaded');
        $this->assertSame('name', $response->json('data.column_mapping.nome'));
        $this->assertSame('cpf', $response->json('data.column_mapping.cpf'));
    }

    public function test_birth_date_column_is_recognized_by_alias(): void
    {
        $vet = User::factory()->professional()->create();
        $file = UploadedFile::fake()->createWithContent('clientes.csv', "nome,data_nascimento\nJoão Silva,05/03/1990\n");

        $response = $this->actingAs($vet)->post('/api/data-imports', [
            'entity' => 'clients',
            'file' => $file,
        ], ['Accept' => 'application/json']);

        $response->assertCreated();
        $this->assertSame('birth_date', $response->json('data.column_mapping.data_nascimento'));
    }

    public function test_uploading_unsupported_entity_returns_422_not_500(): void
    {
        $vet = User::factory()->professional()->create();
        $file = UploadedFile::fake()->createWithContent('servicos.csv', "nome,preco\nBanho,50\n");

        $response = $this->actingAs($vet)->post('/api/data-imports', [
            'entity' => 'services',
            'file' => $file,
        ], ['Accept' => 'application/json']);

        $response->assertStatus(422);
        $response->assertJsonPath('entity', 'services');
    }

    public function test_upload_response_includes_all_raw_headers_not_only_recognized_ones(): void
    {
        $vet = User::factory()->professional()->create();
        $file = UploadedFile::fake()->createWithContent('clientes.csv', "nome,cpf,coluna_desconhecida\nJoão Silva,12345678909,x\n");

        $response = $this->actingAs($vet)->post('/api/data-imports', [
            'entity' => 'clients',
            'file' => $file,
        ], ['Accept' => 'application/json']);

        $response->assertCreated();
        $this->assertSame(['nome', 'cpf', 'coluna_desconhecida'], $response->json('data.raw_headers'));
        $this->assertArrayNotHasKey('coluna_desconhecida', $response->json('data.column_mapping'));
    }

    public function test_execute_dispatches_import_batch_job_on_the_queue(): void
    {
        Queue::fake();
        $vet = User::factory()->professional()->create();
        $import = $this->readyImportFor($vet);

        $response = $this->actingAs($vet)
            ->postJson("/api/data-imports/{$import->id}/execute", ['duplicate_strategy' => 'skip']);

        $response->assertStatus(202);
        $this->assertSame(ImportStatus::IMPORTING->value, $import->fresh()->status);
        Queue::assertPushed(ImportBatchJob::class);
    }

    public function test_execute_requires_a_duplicate_strategy_choice(): void
    {
        $vet = User::factory()->professional()->create();
        $import = $this->readyImportFor($vet);

        $this->actingAs($vet)
            ->postJson("/api/data-imports/{$import->id}/execute", [])
            ->assertStatus(422);
    }

    public function test_import_execution_creates_client_with_tutor_role_and_no_communication_consent(): void
    {
        Mail::fake();
        Notification::fake();
        $vet = User::factory()->professional()->create();
        $import = $this->readyImportFor($vet, [
            'name' => 'Maria Souza',
            'cpf' => '98765432100',
            'email' => 'maria@example.com',
            'phone' => '11988887777',
        ]);

        $this->actingAs($vet)
            ->postJson("/api/data-imports/{$import->id}/execute", ['duplicate_strategy' => 'skip'])
            ->assertStatus(202);

        $client = User::where('email', 'maria@example.com')->first();
        $this->assertNotNull($client);
        $this->assertTrue($client->hasRole('tutor'));
        $this->assertSame(0, ConsentLog::count());
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_rollback_removes_client_created_by_the_import(): void
    {
        $vet = User::factory()->professional()->create();
        $client = User::factory()->tutor()->create();
        $import = DataImport::create([
            'professional_id' => $vet->id,
            'user_id' => $vet->id,
            'entity' => 'clients',
            'file_path' => 'data-imports/fake.csv',
            'status' => ImportStatus::COMPLETED->value,
            'batch_uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);
        $import->rows()->create([
            'row_number' => 1,
            'raw' => ['nome' => $client->name],
            'status' => ImportRowStatus::IMPORTED->value,
            'created_record_type' => User::class,
            'created_record_id' => $client->id,
        ]);
        ProfessionalClient::create(['professional_id' => $vet->id, 'client_id' => $client->id]);

        $response = $this->actingAs($vet)->postJson("/api/data-imports/{$import->id}/rollback");

        $response->assertOk();
        $response->assertJsonPath('rolled_back', 1);
        $this->assertNull(User::find($client->id));
        $this->assertSame(ImportStatus::ROLLED_BACK->value, $import->fresh()->status);
    }

    public function test_rollback_refuses_client_with_pet_created_after_import(): void
    {
        $vet = User::factory()->professional()->create();
        $client = User::factory()->tutor()->create();
        Pet::factory()->create(['user_id' => $client->id]);
        $import = DataImport::create([
            'professional_id' => $vet->id,
            'user_id' => $vet->id,
            'entity' => 'clients',
            'file_path' => 'data-imports/fake.csv',
            'status' => ImportStatus::COMPLETED->value,
            'batch_uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);
        $import->rows()->create([
            'row_number' => 1,
            'raw' => ['nome' => $client->name],
            'status' => ImportRowStatus::IMPORTED->value,
            'created_record_type' => User::class,
            'created_record_id' => $client->id,
        ]);

        $response = $this->actingAs($vet)->postJson("/api/data-imports/{$import->id}/rollback");

        $response->assertOk();
        $response->assertJsonPath('rolled_back', 0);
        $this->assertCount(1, $response->json('refused'));
        $this->assertNotNull(User::find($client->id));
    }

    public function test_export_errors_returns_csv_of_invalid_rows(): void
    {
        $vet = User::factory()->professional()->create();
        $import = DataImport::create([
            'professional_id' => $vet->id,
            'user_id' => $vet->id,
            'entity' => 'clients',
            'file_path' => 'data-imports/fake.csv',
            'status' => ImportStatus::READY->value,
            'batch_uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);
        $import->rows()->create([
            'row_number' => 3,
            'raw' => ['nome' => ''],
            'status' => ImportRowStatus::INVALID->value,
            'errors' => ['Nome é obrigatório.'],
        ]);

        $response = $this->actingAs($vet)->get("/api/data-imports/{$import->id}/errors/export");

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Nome é obrigatório.', $response->streamedContent());
    }

    public function test_template_endpoint_returns_csv_header_for_supported_entity(): void
    {
        $vet = User::factory()->professional()->create();

        $response = $this->actingAs($vet)->get('/api/data-imports/templates/clients');

        $response->assertOk();
        $this->assertStringContainsString('nome', $response->streamedContent());
    }

    public function test_organization_owner_only_sees_imports_of_their_own_organization(): void
    {
        $vetA = User::factory()->professional()->create();
        $vetB = User::factory()->professional()->create();
        $this->readyImportFor($vetA);
        $importB = $this->readyImportFor($vetB);

        $response = $this->actingAs($vetA)->getJson('/api/data-imports');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertNotContains($importB->id, $ids);
    }

    /**
     * @param  array<string, string>  $normalized
     */
    private function readyImportFor(User $professional, array $normalized = []): DataImport
    {
        $import = DataImport::create([
            'professional_id' => $professional->id,
            'user_id' => $professional->id,
            'entity' => 'clients',
            'file_path' => 'data-imports/fake.csv',
            'status' => ImportStatus::READY->value,
            'valid_rows' => 1,
            'batch_uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $import->rows()->create([
            'row_number' => 1,
            'raw' => $normalized,
            'normalized' => $normalized === [] ? ['name' => 'Cliente Teste'] : $normalized,
            'status' => ImportRowStatus::VALID->value,
        ]);

        return $import;
    }
}
