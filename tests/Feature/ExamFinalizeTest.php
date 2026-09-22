<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
use App\Models\Exam;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `POST /exams/{id}/finalize` — contrato spec 16, regras 3/5: finalizar nunca trava o
 * fechamento do agendamento (`Exam.status` é relógio separado, decisão já tomada no doc 10).
 */
class ExamFinalizeTest extends TestCase
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

    public function test_finalizing_persists_the_report_and_completes_the_exam(): void
    {
        $exam = Exam::create([
            'pet_id' => $this->pet->id,
            'professional_id' => $this->vet->id,
            'exam_type' => 'imaging',
            'exam_name' => 'Radiografia',
            'exam_date' => now()->toDateString(),
        ]);

        $response = $this->postJson("/api/exams/{$exam->id}/finalize", [
            'report_html' => '<p>Laudo completo.</p>',
            'findings' => 'Sem achados relevantes.',
            'conclusion' => 'Animal hígido.',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.findings', 'Sem achados relevantes.');

        $this->assertDatabaseHas('exams', ['id' => $exam->id, 'status' => 'completed']);
    }

    public function test_a_vet_without_pet_vet_access_cannot_finalize(): void
    {
        $exam = Exam::create([
            'pet_id' => $this->pet->id,
            'professional_id' => $this->vet->id,
            'exam_type' => 'imaging',
            'exam_name' => 'Radiografia',
            'exam_date' => now()->toDateString(),
        ]);

        $stranger = User::factory()->professional()->create();
        Sanctum::actingAs($stranger);

        $this->postJson("/api/exams/{$exam->id}/finalize", ['conclusion' => 'Tentativa indevida.'])
            ->assertStatus(403);
    }
}
