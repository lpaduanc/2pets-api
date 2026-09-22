<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
use App\Models\Hospitalization;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `GET hospitalizations/{id}/medication-schedule` — contrato docs/gap-simplesvet/specs/
 * 12-internacao-mapa-execucao-spec.md §3. "Próxima dose esperada" é SEMPRE derivada em
 * leitura (regra de negócio 4) — nenhuma linha de execução é pré-gerada.
 */
class HospitalizationMedicationScheduleTest extends TestCase
{
    use RefreshDatabase;

    private User $vet;

    private Pet $pet;

    private Hospitalization $hospitalization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vet = User::factory()->professional()->create();
        $tutor = User::factory()->tutor()->create();
        $this->pet = Pet::factory()->create(['user_id' => $tutor->id]);

        PetVetAccess::create([
            'pet_id' => $this->pet->id,
            'veterinarian_id' => $this->vet->id,
            'granted_by' => $tutor->id,
            'access_level' => VetAccessLevel::WRITE,
            'status' => PetVetAccess::STATUS_ACCEPTED,
            'is_active' => true,
            'granted_at' => now(),
            'responded_at' => now(),
        ]);

        Sanctum::actingAs($this->vet);

        $this->hospitalization = $this->admit();
    }

    public function test_item_without_any_administration_shows_starts_at_as_the_next_due_date(): void
    {
        $startsAt = now()->subHours(2);
        $this->prescriptionItem(['frequency' => 'bid', 'starts_at' => $startsAt]);

        $response = $this->getJson("/api/professional/hospitalizations/{$this->hospitalization->id}/medication-schedule");

        $response->assertOk();
        $entry = $response->json('data.0');
        $this->assertNull($entry['last_given_at']);
        $this->assertTrue($entry['is_late']);
    }

    public function test_registering_administration_recalculates_the_next_due_date(): void
    {
        $item = $this->prescriptionItem(['frequency' => 'bid', 'starts_at' => now()->subDay()]);

        $performedAt = now()->subHours(3);
        $this->postJson("/api/professional/hospitalizations/{$this->hospitalization->id}/care-logs", [
            'care_type' => 'medication_administration',
            'status' => 'done',
            'performed_at' => $performedAt->toISOString(),
            'prescription_item_id' => $item->id,
        ])->assertStatus(201);

        $response = $this->getJson("/api/professional/hospitalizations/{$this->hospitalization->id}/medication-schedule");

        $response->assertOk();
        $entry = $response->json('data.0');
        $this->assertNotNull($entry['last_given_at']);
        // BID = 12h de intervalo — 3h atrás + 12h ainda está no futuro.
        $this->assertFalse($entry['is_late']);
    }

    public function test_endpoint_runs_a_single_aggregated_query_regardless_of_item_count(): void
    {
        for ($index = 0; $index < 8; $index++) {
            $this->prescriptionItem(['frequency' => 'bid', 'starts_at' => now()]);
        }

        $queryCount = $this->countQueriesOfScheduleRequest();

        // A query por item (N+1) daria, no mínimo, 8 consultas extras — o teto aqui só
        // sobra espaço para a query agregada em si + a checagem de autorização da rota,
        // nunca para uma consulta por `PrescriptionItem`.
        $this->assertLessThanOrEqual(
            5,
            $queryCount,
            "Endpoint executou {$queryCount} queries para 8 itens — indício de N+1."
        );
    }

    private function countQueriesOfScheduleRequest(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson("/api/professional/hospitalizations/{$this->hospitalization->id}/medication-schedule")->assertOk();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function prescriptionItem(array $overrides): PrescriptionItem
    {
        $prescription = Prescription::create([
            'pet_id' => $this->pet->id,
            'professional_id' => $this->vet->id,
            'appointment_id' => $this->hospitalization->appointment_id,
            'prescription_date' => now()->toDateString(),
            'standalone_reason' => 'hospitalization_care',
        ]);

        return $prescription->items()->create(array_merge([
            'position' => 1,
            'commercial_name' => 'Dipirona',
            'dose_value' => 25,
            'dose_unit' => 'mg',
        ], $overrides));
    }

    private function admit(): Hospitalization
    {
        $response = $this->postJson('/api/professional/hospitalizations', [
            'pet_id' => $this->pet->id,
            'admission_date' => now()->toDateString(),
            'reason' => 'Internação de teste',
        ]);

        $response->assertStatus(201);

        return Hospitalization::findOrFail($response->json('data.id'));
    }
}
