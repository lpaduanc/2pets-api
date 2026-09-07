<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
use App\Models\MedicalRecord;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `medical_records.symptoms` — o mesmo duplo-encode que quebrou a tela de prescrições.
 *
 * O model castea `symptoms` como `array` (o Eloquent já serializa) e o `MedicalDataSeeder`
 * chamava `json_encode()` antes de gravar: o banco ficava com uma string JSON dentro de JSON e
 * o cliente iterava os caracteres da string. Aqui trava-se o caminho de escrita e o reparo das
 * linhas legadas.
 */
class MedicalRecordSymptomsEncodingTest extends TestCase
{
    use RefreshDatabase;

    private const REPAIR_MIGRATION = 'migrations/2026_09_06_210100_repair_double_encoded_medical_record_symptoms.php';

    private User $professional;

    private Pet $pet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->professional = User::factory()->professional()->create();
        $tutor = User::factory()->tutor()->create();
        $this->pet = Pet::factory()->create(['user_id' => $tutor->id]);

        PetVetAccess::create([
            'pet_id' => $this->pet->id,
            'veterinarian_id' => $this->professional->id,
            'granted_by' => $tutor->id,
            'access_level' => VetAccessLevel::WRITE,
            'status' => PetVetAccess::STATUS_ACCEPTED,
            'is_active' => true,
            'granted_at' => now(),
            'responded_at' => now(),
        ]);

        Sanctum::actingAs($this->professional);
    }

    public function test_store_persists_symptoms_as_a_json_array_not_a_string(): void
    {
        $response = $this->postJson('/api/professional/medical-records', [
            'pet_id' => $this->pet->id,
            'record_date' => now()->toDateString(),
            'symptoms' => ['apatia', 'anorexia', 'desidratação'],
            'diagnosis' => 'Gastroenterite',
        ]);

        $response->assertCreated();

        $this->assertSame('array', $this->storedJsonTypeOf((int) $response->json('record.id')));
    }

    public function test_a_stored_record_reads_back_as_a_list_of_symptoms(): void
    {
        $record = $this->createRecord(['apatia', 'anorexia']);

        $this->assertSame(['apatia', 'anorexia'], MedicalRecord::findOrFail($record->id)->symptoms);
    }

    public function test_the_repair_migration_unwraps_a_legacy_double_encoded_row(): void
    {
        $record = $this->createRecord([]);
        $this->corrupt($record->id, ['apatia', 'anorexia', 'desidratação']);

        $this->assertSame('string', $this->storedJsonTypeOf($record->id));

        $this->runRepairMigration();

        $this->assertSame('array', $this->storedJsonTypeOf($record->id));
        $this->assertSame(
            ['apatia', 'anorexia', 'desidratação'],
            MedicalRecord::findOrFail($record->id)->symptoms
        );
    }

    public function test_the_repair_migration_handles_a_double_encoded_empty_list(): void
    {
        $record = $this->createRecord([]);
        $this->corrupt($record->id, []);

        $this->runRepairMigration();

        $this->assertSame('array', $this->storedJsonTypeOf($record->id));
        $this->assertSame([], MedicalRecord::findOrFail($record->id)->symptoms);
    }

    public function test_the_repair_migration_leaves_correct_rows_untouched_and_can_run_twice(): void
    {
        $healthy = $this->createRecord(['tosse']);
        $corrupted = $this->createRecord([]);
        $this->corrupt($corrupted->id, ['vômito']);

        $this->runRepairMigration();
        $this->runRepairMigration();

        $this->assertSame(['tosse'], MedicalRecord::findOrFail($healthy->id)->symptoms);
        $this->assertSame(['vômito'], MedicalRecord::findOrFail($corrupted->id)->symptoms);
    }

    /**
     * Reproduz exatamente o que o seeder gravava: `json_encode` sobre um valor que o cast já serializa.
     *
     * @param  list<string>  $symptoms
     */
    private function corrupt(int $recordId, array $symptoms): void
    {
        DB::table('medical_records')
            ->where('id', $recordId)
            ->update(['symptoms' => json_encode(json_encode($symptoms))]);
    }

    /**
     * @param  list<string>  $symptoms
     */
    private function createRecord(array $symptoms): MedicalRecord
    {
        return MedicalRecord::create([
            'pet_id' => $this->pet->id,
            'professional_id' => $this->professional->id,
            'record_date' => now()->toDateString(),
            'symptoms' => $symptoms,
        ]);
    }

    private function storedJsonTypeOf(int $recordId): string
    {
        return (string) DB::table('medical_records')
            ->where('id', $recordId)
            ->selectRaw('json_typeof(symptoms) as json_type')
            ->value('json_type');
    }

    private function runRepairMigration(): void
    {
        /** @var Migration $migration */
        $migration = require database_path(self::REPAIR_MIGRATION);

        $migration->up();
    }
}
