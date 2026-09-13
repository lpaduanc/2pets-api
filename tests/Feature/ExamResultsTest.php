<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\Pet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression test for the missing `exam_results` table: `POST
 * /api/exams/{examId}/results` used to fail with `relation "exam_results"
 * does not exist` on every call, even though the exam itself was created
 * successfully.
 */
class ExamResultsTest extends TestCase
{
    use RefreshDatabase;

    private User $tutor;

    private Pet $pet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tutor = User::factory()->tutor()->create();
        $this->pet = Pet::factory()->create(['user_id' => $this->tutor->id]);
    }

    public function test_it_persists_results_for_an_existing_exam(): void
    {
        Sanctum::actingAs($this->tutor);

        $exam = Exam::create([
            'pet_id' => $this->pet->id,
            'professional_id' => $this->tutor->id,
            'exam_type' => 'blood',
            'exam_name' => 'Hemograma completo',
            'exam_date' => now()->toDateString(),
        ]);

        $response = $this->postJson("/api/exams/{$exam->id}/results", [
            'results' => [
                [
                    'parameter' => 'Hemoglobina',
                    'value' => '14.2',
                    'unit' => 'g/dL',
                    'reference_range' => '12.0-18.0',
                    'status' => 'normal',
                ],
            ],
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('exam_results', [
            'exam_id' => $exam->id,
            'parameter' => 'Hemoglobina',
            'value' => '14.2',
            'unit' => 'g/dL',
            'reference_range' => '12.0-18.0',
            'status' => 'normal',
        ]);

        $this->assertSame('completed', $exam->fresh()->status);
    }

    public function test_it_reports_the_recorded_result_in_the_parameter_history(): void
    {
        Sanctum::actingAs($this->tutor);

        $exam = Exam::create([
            'pet_id' => $this->pet->id,
            'professional_id' => $this->tutor->id,
            'exam_type' => 'blood',
            'exam_name' => 'Hemograma completo',
            'exam_date' => now()->subDays(5)->toDateString(),
        ]);

        $this->postJson("/api/exams/{$exam->id}/results", [
            'results' => [
                ['parameter' => 'Hemoglobina', 'value' => '14.2', 'unit' => 'g/dL'],
            ],
        ])->assertOk();

        $response = $this->getJson("/api/exams/pet/{$this->pet->id}/history/Hemoglobina")
            ->assertOk();

        $response->assertJsonPath('data.parameter', 'Hemoglobina')
            ->assertJsonPath('data.history.0.value', '14.2')
            ->assertJsonPath('data.history.0.unit', 'g/dL');
    }
}
