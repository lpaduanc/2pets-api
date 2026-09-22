<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
use App\Models\Hospitalization;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regra de negócio 3 da spec docs/gap-simplesvet/specs/12-internacao-mapa-execucao-spec.md:
 * `prescription_items.starts_at`, quando a prescrição pendura numa internação, não pode cair
 * antes da admissão nem depois da alta.
 */
class PrescriptionItemStayWindowTest extends TestCase
{
    use RefreshDatabase;

    private User $vet;

    private Pet $pet;

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
    }

    public function test_starts_at_before_admission_is_rejected(): void
    {
        $hospitalization = $this->admit();

        $response = $this->postJson('/api/professional/prescriptions', $this->payload(
            $hospitalization,
            now()->subDays(3)->toISOString(),
        ));

        $response->assertStatus(422);
    }

    public function test_starts_at_within_stay_is_accepted(): void
    {
        $hospitalization = $this->admit();

        $response = $this->postJson('/api/professional/prescriptions', $this->payload(
            $hospitalization,
            now()->addHour()->toISOString(),
        ));

        $response->assertStatus(201);
        $this->assertSame(
            $hospitalization->appointment_id,
            $response->json('data.appointment_id'),
        );
    }

    public function test_starts_at_after_discharge_is_rejected(): void
    {
        $hospitalization = $this->admit();
        $hospitalization->update([
            'status' => 'discharged',
            'discharge_date' => now()->toDateString(),
            'discharge_summary' => 'Alta sem intercorrências.',
        ]);

        $response = $this->postJson('/api/professional/prescriptions', $this->payload(
            $hospitalization,
            now()->addDays(5)->toISOString(),
        ));

        $response->assertStatus(422);
    }

    public function test_standalone_prescription_without_hospitalization_ignores_the_rule(): void
    {
        $response = $this->postJson('/api/professional/prescriptions', [
            'pet_id' => $this->pet->id,
            'prescription_date' => now()->toDateString(),
            'standalone_reason' => 'remote_orientation',
            'items' => [[
                'commercial_name' => 'Dipirona',
                'starts_at' => now()->subYears(2)->toISOString(),
            ]],
        ]);

        $response->assertStatus(201);
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

    /**
     * @return array<string, mixed>
     */
    private function payload(Hospitalization $hospitalization, string $startsAt): array
    {
        return [
            'pet_id' => $this->pet->id,
            'prescription_date' => now()->toDateString(),
            'appointment_id' => $hospitalization->appointment_id,
            'standalone_reason' => 'hospitalization_care',
            'items' => [[
                'commercial_name' => 'Dipirona',
                'dose_value' => 500,
                'dose_unit' => 'mg',
                'frequency' => 'bid',
                'starts_at' => $startsAt,
            ]],
        ];
    }
}
