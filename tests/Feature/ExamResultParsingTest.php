<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\Pet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `results.*.value` continua string livre (há resultado legitimamente não numérico), mas
 * agora também guarda `value_numeric` quando o texto é parseável em pt-BR (vírgula
 * decimal), e deriva `status` automaticamente comparando com `reference_min`/`reference_max`
 * extraídos de `reference_range` — sem isso, "eosinófilos 1850 com referência 100–1.250" só
 * virava alterado se alguém lembrasse de marcar à mão.
 */
class ExamResultParsingTest extends TestCase
{
    use RefreshDatabase;

    private User $tutor;

    private Pet $pet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tutor = User::factory()->tutor()->create();
        $this->pet = Pet::factory()->create(['user_id' => $this->tutor->id]);

        Sanctum::actingAs($this->tutor);
    }

    private function createExam(): Exam
    {
        return Exam::create([
            'pet_id' => $this->pet->id,
            'professional_id' => $this->tutor->id,
            'exam_type' => 'blood',
            'exam_name' => 'Hemograma completo',
            'exam_date' => now()->toDateString(),
        ]);
    }

    public function test_it_parses_comma_decimal_value_and_derives_normal_status(): void
    {
        $exam = $this->createExam();

        $this->postJson("/api/exams/{$exam->id}/results", [
            'results' => [
                [
                    'parameter' => 'Hemoglobina',
                    'value' => '14,2',
                    'reference_range' => '12,0-18,0',
                ],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('exam_results', [
            'parameter' => 'Hemoglobina',
            'value' => '14,2',
            'value_numeric' => 14.2,
            'reference_min' => 12.0,
            'reference_max' => 18.0,
            'status' => 'normal',
        ]);
    }

    /**
     * "1.250" no padrão pt-BR é separador de milhar (mil duzentos e cinquenta), não decimal.
     */
    public function test_it_parses_thousands_separator_in_reference_range_and_derives_high_status(): void
    {
        $exam = $this->createExam();

        $this->postJson("/api/exams/{$exam->id}/results", [
            'results' => [
                [
                    'parameter' => 'Eosinófilos',
                    'value' => '1850',
                    'reference_range' => '100–1.250',
                ],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('exam_results', [
            'parameter' => 'Eosinófilos',
            'value_numeric' => 1850,
            'reference_min' => 100,
            'reference_max' => 1250,
            'status' => 'high',
        ]);
    }

    public function test_it_derives_low_status_when_value_is_below_reference_min(): void
    {
        $exam = $this->createExam();

        $this->postJson("/api/exams/{$exam->id}/results", [
            'results' => [
                ['parameter' => 'Plaquetas', 'value' => '90000', 'reference_range' => '150000-450000'],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('exam_results', [
            'parameter' => 'Plaquetas',
            'status' => 'low',
        ]);
    }

    /**
     * Valor não numérico legítimo: preserva o texto, `value_numeric` fica null, e sem
     * numérico não há como derivar `status` sozinho.
     */
    public function test_it_preserves_non_numeric_value_without_forcing_a_number(): void
    {
        $exam = $this->createExam();

        $this->postJson("/api/exams/{$exam->id}/results", [
            'results' => [
                ['parameter' => 'VDRL', 'value' => 'Reagente'],
                ['parameter' => 'Glicose em jejum', 'value' => '<0,1'],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('exam_results', [
            'parameter' => 'VDRL',
            'value' => 'Reagente',
            'value_numeric' => null,
            'status' => null,
        ]);

        $this->assertDatabaseHas('exam_results', [
            'parameter' => 'Glicose em jejum',
            'value' => '<0,1',
            'value_numeric' => null,
        ]);
    }

    public function test_manual_status_always_wins_over_the_derived_one(): void
    {
        $exam = $this->createExam();

        $this->postJson("/api/exams/{$exam->id}/results", [
            'results' => [
                [
                    'parameter' => 'Hemoglobina',
                    'value' => '14,2',
                    'reference_range' => '12,0-18,0',
                    'status' => 'critical',
                ],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('exam_results', [
            'parameter' => 'Hemoglobina',
            'status' => 'critical',
        ]);
    }

    public function test_it_rejects_an_unknown_status_value(): void
    {
        $exam = $this->createExam();

        $response = $this->postJson("/api/exams/{$exam->id}/results", [
            'results' => [
                ['parameter' => 'Hemoglobina', 'value' => '14,2', 'status' => 'not-a-status'],
            ],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('results.0.status');
    }

    /**
     * O endpoint de tendência (`GET /exams/pet/{petId}/history/{parameter}`) passa a expor
     * o numérico e a faixa junto do texto — é o que viabiliza o gráfico de evolução.
     */
    public function test_history_endpoint_exposes_numeric_value_and_reference_bounds(): void
    {
        $exam = $this->createExam();

        $this->postJson("/api/exams/{$exam->id}/results", [
            'results' => [
                ['parameter' => 'Hemoglobina', 'value' => '14,2', 'reference_range' => '12,0-18,0'],
            ],
        ])->assertOk();

        $response = $this->getJson("/api/exams/pet/{$this->pet->id}/history/Hemoglobina")->assertOk();

        $response->assertJsonPath('data.history.0.value', '14,2')
            ->assertJsonPath('data.history.0.value_numeric', 14.2)
            ->assertJsonPath('data.history.0.reference_min', 12)
            ->assertJsonPath('data.history.0.reference_max', 18);
    }
}
