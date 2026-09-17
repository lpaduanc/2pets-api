<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
use App\Models\MedicalRecord;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regressão de 2026-09-16: o bloco `reported_pet_data` EM BRANCO — o estado padrão da tela de
 * consulta, antes de o vet tocar em qualquer campo — devolvia 422 e travava o rascunho inteiro.
 * O formulário manda o bloco completo com `''` nos campos não preenchidos, o middleware global
 * `ConvertEmptyStringsToNull` os transforma em `null`, e `ValidReportedPetData` exige valor
 * tipado em toda chave PRESENTE. Consequência: nem o "Salvar" manual nem o autosave de 20s
 * conseguiam gravar a consulta.
 *
 * A correção é `ReportedPetDataNormalizer` (chamado no `prepareForValidation`): campo em branco
 * vira chave ausente ANTES de validar, e por isso também nunca chega ao banco — o que importa
 * porque `PetDataPromotionService` decide o que promover ao cadastro por presença de chave.
 *
 * Escrito conforme a regra do projeto: teste escrito, NÃO executado via `artisan test`.
 */
class MedicalRecordReportedPetDataBlankTest extends TestCase
{
    use RefreshDatabase;

    private User $professional;

    private Pet $pet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->professional = User::factory()->professional()->create();
        $tutor = User::factory()->tutor()->create();
        $this->pet = Pet::factory()->create(['user_id' => $tutor->id, 'species' => 'dog']);

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

    /** O corpo exato que a tela manda com o bloco intocado. */
    public function test_draft_saves_with_an_untouched_reported_pet_data_block(): void
    {
        $record = $this->createDraft();

        $this->putJson("/api/professional/medical-records/{$record->id}", [
            'status' => 'draft',
            'subjective' => 'tutor relata apatia desde ontem',
            'reported_pet_data' => $this->blankBlock(),
        ])->assertOk();

        $this->assertSame([], MedicalRecord::findOrFail($record->id)->reported_pet_data);
    }

    public function test_blank_fields_are_dropped_and_informed_fields_are_kept(): void
    {
        $record = $this->createDraft();

        $this->putJson("/api/professional/medical-records/{$record->id}", [
            'reported_pet_data' => [
                ...$this->blankBlock(),
                'weight_kg' => 8.4,
                'docile_with_strangers' => 'depends',
                'feeding_types' => ['dry_food'],
            ],
        ])->assertOk();

        $stored = MedicalRecord::findOrFail($record->id)->reported_pet_data;

        $this->assertSame(8.4, (float) $stored['weight_kg']);
        $this->assertSame('depends', $stored['docile_with_strangers']);
        $this->assertSame(['dry_food'], $stored['feeding_types']);
        $this->assertArrayNotHasKey('food_brand', $stored);
        $this->assertArrayNotHasKey('docile_with_animals', $stored);
        $this->assertArrayNotHasKey('is_neutered', $stored);
    }

    /** `false` é resposta ("não passeia"), não ausência de resposta — não pode ser podado. */
    public function test_false_survives_the_blank_pruning(): void
    {
        $record = $this->createDraft();

        $this->putJson("/api/professional/medical-records/{$record->id}", [
            'reported_pet_data' => [
                'physical_activity' => ['does' => 'no', 'type' => [], 'weekly_frequency' => null, 'daily_walk' => false],
            ],
        ])->assertOk();

        $stored = MedicalRecord::findOrFail($record->id)->reported_pet_data;

        $this->assertFalse($stored['physical_activity']['daily_walk']);
        $this->assertSame('no', $stored['physical_activity']['does']);
        $this->assertArrayNotHasKey('weekly_frequency', $stored['physical_activity']);
        $this->assertArrayNotHasKey('type', $stored['physical_activity']);
    }

    /** Linha de medicamento aberta e não preenchida não pode derrubar o rascunho. */
    public function test_empty_medication_row_is_dropped_instead_of_failing_the_draft(): void
    {
        $record = $this->createDraft();

        $this->putJson("/api/professional/medical-records/{$record->id}", [
            'reported_pet_data' => [
                'continuous_medications' => [
                    ['name' => '', 'dosage' => '', 'frequency' => ''],
                    ['name' => 'Enalapril', 'dosage' => '2,5mg', 'frequency' => ''],
                ],
            ],
        ])->assertOk();

        $stored = MedicalRecord::findOrFail($record->id)->reported_pet_data;

        $this->assertCount(1, $stored['continuous_medications']);
        $this->assertSame('Enalapril', $stored['continuous_medications'][0]['name']);
        $this->assertArrayNotHasKey('frequency', $stored['continuous_medications'][0]);
    }

    /** Podar o vazio não afrouxa a regra: valor fora da taxonomia continua 422. */
    public function test_a_real_invalid_value_is_still_rejected(): void
    {
        $record = $this->createDraft();

        $this->putJson("/api/professional/medical-records/{$record->id}", [
            'reported_pet_data' => ['docile_with_strangers' => 'talvez'],
        ])->assertStatus(422)->assertJsonValidationErrors('reported_pet_data');
    }

    /** Um `PUT` que não menciona o bloco não pode zerá-lo. */
    public function test_omitting_the_block_leaves_the_stored_one_untouched(): void
    {
        $record = $this->createDraft();
        $record->forceFill(['reported_pet_data' => ['weight_kg' => 8.4]])->save();

        $this->putJson("/api/professional/medical-records/{$record->id}", [
            'subjective' => 'retorno de 7 dias',
        ])->assertOk();

        $this->assertSame(8.4, (float) MedicalRecord::findOrFail($record->id)->reported_pet_data['weight_kg']);
    }

    /**
     * @return array<string, mixed>
     */
    private function blankBlock(): array
    {
        return [
            'weight_kg' => null,
            'birth_date' => null,
            'is_neutered' => '',
            'feeding_types' => [],
            'food_brand' => '',
            'dietary_restrictions' => [],
            'food_allergies' => [],
            'chronic_conditions' => [],
            'continuous_medications' => [],
            'docile_with_strangers' => '',
            'docile_with_animals' => '',
            'physical_activity' => ['does' => '', 'type' => [], 'weekly_frequency' => '', 'daily_walk' => null],
        ];
    }

    private function createDraft(): MedicalRecord
    {
        return MedicalRecord::create([
            'pet_id' => $this->pet->id,
            'professional_id' => $this->professional->id,
            'record_date' => now()->toDateString(),
            'status' => 'draft',
        ]);
    }
}
