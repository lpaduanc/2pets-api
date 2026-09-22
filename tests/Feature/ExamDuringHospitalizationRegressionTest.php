<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
use App\Models\Hospitalization;
use App\Models\Invoice;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regressão explícita — contrato spec 16, critério de aceite: "um exame criado durante
 * internação ativa continua funcionando sem alteração depois que exam_type_id/report_html
 * forem adicionados" (doc 11 §2.2, `HospitalizationExamService`).
 */
class ExamDuringHospitalizationRegressionTest extends TestCase
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

    public function test_an_exam_created_during_an_active_stay_still_hangs_on_the_stay_invoice(): void
    {
        $admission = $this->postJson('/api/professional/hospitalizations', [
            'pet_id' => $this->pet->id,
            'admission_date' => now()->toDateString(),
            'reason' => 'Internação de teste',
        ]);
        $admission->assertStatus(201);
        $hospitalization = Hospitalization::findOrFail($admission->json('data.id'));

        $response = $this->postJson('/api/exams', [
            'pet_id' => $this->pet->id,
            'exam_type' => 'laboratory',
            'exam_name' => 'Hemograma de controle',
            'exam_date' => now()->toDateString(),
            'unit_price' => 80,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.appointment_id', $hospitalization->appointment_id);

        $this->assertSame(1, Invoice::where('appointment_id', $hospitalization->appointment_id)->count());
        $this->assertDatabaseHas('appointment_charges', [
            'appointment_id' => $hospitalization->appointment_id,
            'description' => 'Hemograma de controle — durante internação',
        ]);
    }
}
