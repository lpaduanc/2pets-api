<?php

namespace App\Support;

use App\Models\Pet;
use Closure;

/**
 * Mapa `medical_records.reported_pet_data` → colunas de `pets` — contrato
 * docs/atendimento-veterinario/08-consulta-autorizada-por-agendamento.md §D. `is_neutered` e
 * `physical_activity` escrevem em mais de uma coluna; o resto é 1:1. Reaproveita os MESMOS
 * slugs já aceitos por `UpdatePetRequest` (`is_neutered`, `docile_with_strangers`/
 * `docile_with_animals`) — nenhuma taxonomia nova nasce aqui.
 *
 * Separado de `PetDataPromotionService` (que só orquestra diff/apply) porque esta tabela é
 * dado de mapeamento, não fluxo — crescer um campo novo aqui não deveria tocar a lógica de
 * transação do Service.
 */
final class PetReportedDataFieldRegistry
{
    /**
     * @return array<string, array{current: Closure, toAttributes: Closure}>
     */
    public function all(): array
    {
        return [
            'weight_kg' => [
                'current' => fn (Pet $pet): ?float => $pet->weight !== null ? (float) $pet->weight : null,
                'toAttributes' => fn (mixed $value): array => ['weight' => $value],
            ],
            'birth_date' => [
                'current' => fn (Pet $pet): ?string => $pet->birth_date?->format('Y-m-d'),
                'toAttributes' => fn (mixed $value): array => ['birth_date' => $value],
            ],
            'is_neutered' => [
                'current' => fn (Pet $pet): ?string => $pet->neutered_status,
                'toAttributes' => fn (mixed $value): array => [
                    'neutered_status' => $value,
                    'neutered' => $value === 'yes',
                ],
            ],
            'feeding_types' => [
                'current' => fn (Pet $pet): ?array => $pet->food_types,
                'toAttributes' => fn (mixed $value): array => ['food_types' => $value],
            ],
            'food_brand' => [
                'current' => fn (Pet $pet): ?string => $pet->food_brand,
                'toAttributes' => fn (mixed $value): array => ['food_brand' => $value],
            ],
            'dietary_restrictions' => [
                'current' => fn (Pet $pet): ?array => $pet->dietary_restrictions,
                'toAttributes' => fn (mixed $value): array => ['dietary_restrictions' => $value],
            ],
            'food_allergies' => [
                'current' => fn (Pet $pet): ?array => $pet->food_allergies,
                'toAttributes' => fn (mixed $value): array => ['food_allergies' => $value],
            ],
            'chronic_conditions' => [
                'current' => fn (Pet $pet): ?array => $pet->chronic_conditions,
                'toAttributes' => fn (mixed $value): array => ['chronic_conditions' => $value],
            ],
            'continuous_medications' => [
                'current' => fn (Pet $pet): ?array => $pet->current_medications,
                'toAttributes' => fn (mixed $value): array => ['current_medications' => $value],
            ],
            'docile_with_strangers' => [
                'current' => fn (Pet $pet): ?string => $pet->docile_with_strangers,
                'toAttributes' => fn (mixed $value): array => ['docile_with_strangers' => $value],
            ],
            'docile_with_animals' => [
                'current' => fn (Pet $pet): ?string => $pet->docile_with_animals,
                'toAttributes' => fn (mixed $value): array => ['docile_with_animals' => $value],
            ],
            'physical_activity' => [
                'current' => fn (Pet $pet): array => $this->currentPhysicalActivity($pet),
                'toAttributes' => fn (mixed $value): array => $this->physicalActivityAttributes((array) $value),
            ],
        ];
    }

    private function currentPhysicalActivity(Pet $pet): array
    {
        return [
            'does' => $pet->does_exercise,
            'type' => $pet->exercise_types,
            'weekly_frequency' => $pet->exercise_frequency,
            'daily_walk' => $pet->daily_walk,
        ];
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function physicalActivityAttributes(array $value): array
    {
        $map = [
            'does' => 'does_exercise',
            'type' => 'exercise_types',
            'weekly_frequency' => 'exercise_frequency',
            'daily_walk' => 'daily_walk',
        ];

        $attributes = [];
        foreach ($map as $reportedKey => $petColumn) {
            if (array_key_exists($reportedKey, $value)) {
                $attributes[$petColumn] = $value[$reportedKey];
            }
        }

        return $attributes;
    }
}
