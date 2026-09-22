<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
use App\Models\ExamType;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Criar um exame de um tipo cadastrado traz o laudo pré-montado (apresentação + encerramento),
 * editável sem afetar o template do tipo — contrato spec 16, regra de negócio 2.
 */
class ExamReportTemplateTest extends TestCase
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

    public function test_exam_created_from_a_type_inherits_the_report_draft(): void
    {
        $examType = ExamType::create([
            'name' => 'Ultrassonografia abdominal',
            'category' => 'imaging',
            'presentation_html' => 'Exame realizado em decúbito dorsal.',
            'closing_html' => 'Sem outras alterações dignas de nota.',
        ]);

        $response = $this->postJson('/api/exams', [
            'pet_id' => $this->pet->id,
            'exam_type' => 'imaging',
            'exam_name' => 'Ultrassonografia abdominal',
            'exam_type_id' => $examType->id,
            'exam_date' => now()->toDateString(),
        ]);

        $response->assertStatus(201);
        $reportHtml = $response->json('data.report_html');
        $this->assertStringContainsString('decúbito dorsal', $reportHtml);
        $this->assertStringContainsString('Sem outras alterações', $reportHtml);
    }

    /** Regra de negócio 1: exame sem `exam_type_id` (texto livre) continua funcionando. */
    public function test_exam_without_a_catalog_type_still_works_with_free_text(): void
    {
        $response = $this->postJson('/api/exams', [
            'pet_id' => $this->pet->id,
            'exam_type' => 'blood',
            'exam_name' => 'Hemograma completo',
            'exam_date' => now()->toDateString(),
        ]);

        $response->assertStatus(201)->assertJsonPath('data.exam_type_id', null);
    }

    public function test_editing_the_exam_report_does_not_change_the_type_template(): void
    {
        $examType = ExamType::create([
            'name' => 'Raio-X torácico',
            'category' => 'imaging',
            'presentation_html' => 'Apresentação padrão.',
            'closing_html' => 'Encerramento padrão.',
        ]);

        $examId = $this->postJson('/api/exams', [
            'pet_id' => $this->pet->id,
            'exam_type' => 'imaging',
            'exam_name' => 'Raio-X torácico',
            'exam_type_id' => $examType->id,
            'exam_date' => now()->toDateString(),
        ])->json('data.id');

        $this->postJson("/api/exams/{$examId}/finalize", [
            'report_html' => 'Laudo totalmente reescrito pelo veterinário.',
        ])->assertOk();

        $examType->refresh();
        $this->assertSame('Apresentação padrão.', $examType->presentation_html);
        $this->assertSame('Encerramento padrão.', $examType->closing_html);
    }
}
