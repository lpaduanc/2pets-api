<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Valida `medical_records.reported_pet_data` — contrato
 * docs/atendimento-veterinario/08-consulta-autorizada-por-agendamento.md §C: o que o TUTOR
 * informou NA consulta, que pode divergir do cadastro salvo do pet. Chave desconhecida é
 * rejeitada (contrato fechado), nunca ignorada em silêncio.
 *
 * Os slugs de domínio fechado reaproveitam EXATAMENTE os já aceitos por `UpdatePetRequest`/
 * `2pets-app/src/constants/pet-options.js` — nenhuma taxonomia nova nasce aqui.
 */
final class ValidReportedPetData implements ValidationRule
{
    private const NEUTERED_VALUES = ['yes', 'no', 'in_progress'];

    private const DOCILITY_VALUES = ['yes', 'no', 'depends'];

    private const EXERCISE_FREQUENCY_VALUES = ['1x_week', '2_3x_week', '4_5x_week', 'daily'];

    private const MAX_TEXT_LENGTH = 150;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            $fail('Os dados informados na consulta devem ser um objeto.');

            return;
        }

        foreach ($value as $key => $item) {
            $this->validateKey($key, $item, $fail);
        }
    }

    private function validateKey(int|string $key, mixed $item, Closure $fail): void
    {
        $validator = match ($key) {
            'weight_kg' => fn () => $this->validateNumeric($key, $item, $fail, max: 200),
            'birth_date' => fn () => $this->validateDate($key, $item, $fail),
            'is_neutered' => fn () => $this->validateInList($key, $item, self::NEUTERED_VALUES, $fail),
            'feeding_types' => fn () => $this->validateStringList($key, $item, $fail),
            'food_brand' => fn () => $this->validateText($key, $item, $fail),
            'dietary_restrictions' => fn () => $this->validateStringList($key, $item, $fail),
            'food_allergies' => fn () => $this->validateStringList($key, $item, $fail),
            'chronic_conditions' => fn () => $this->validateStringList($key, $item, $fail),
            'continuous_medications' => fn () => $this->validateMedications($item, $fail),
            'docile_with_strangers' => fn () => $this->validateInList($key, $item, self::DOCILITY_VALUES, $fail),
            'docile_with_animals' => fn () => $this->validateInList($key, $item, self::DOCILITY_VALUES, $fail),
            'physical_activity' => fn () => $this->validatePhysicalActivity($item, $fail),
            default => fn () => $fail("O campo \"{$key}\" não é aceito nos dados informados na consulta."),
        };

        $validator();
    }

    private function validateNumeric(int|string $key, mixed $item, Closure $fail, float $max): void
    {
        if (! is_int($item) && ! is_float($item)) {
            $fail("O campo \"{$key}\" deve ser numérico.");

            return;
        }

        if ($item < 0 || $item > $max) {
            $fail("O campo \"{$key}\" está fora da faixa aceita.");
        }
    }

    private function validateDate(int|string $key, mixed $item, Closure $fail): void
    {
        if (! is_string($item) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $item)) {
            $fail("O campo \"{$key}\" deve ser uma data no formato AAAA-MM-DD.");
        }
    }

    /**
     * @param  list<string>  $options
     */
    private function validateInList(int|string $key, mixed $item, array $options, Closure $fail): void
    {
        if (! is_string($item) || ! in_array($item, $options, true)) {
            $fail("O valor informado para \"{$key}\" não é válido.");
        }
    }

    private function validateStringList(int|string $key, mixed $item, Closure $fail): void
    {
        if (! is_array($item)) {
            $fail("O campo \"{$key}\" deve ser uma lista.");

            return;
        }

        foreach ($item as $entry) {
            if (! is_string($entry) || mb_strlen($entry) > self::MAX_TEXT_LENGTH) {
                $fail("Um dos valores de \"{$key}\" é inválido.");

                return;
            }
        }
    }

    private function validateText(int|string $key, mixed $item, Closure $fail): void
    {
        if (! is_string($item) || mb_strlen($item) > self::MAX_TEXT_LENGTH) {
            $fail("O campo \"{$key}\" deve ser um texto de até ".self::MAX_TEXT_LENGTH.' caracteres.');
        }
    }

    /**
     * `continuous_medications` — lista de objetos `{name, dosage, frequency}`. Texto livre nos
     * três campos (mesma regra já aplicada a `UpdatePetRequest.medications.*`): dose e
     * frequência de medicamento não têm taxonomia fechada única no domínio hoje.
     */
    private function validateMedications(mixed $item, Closure $fail): void
    {
        if (! is_array($item)) {
            $fail('"continuous_medications" deve ser uma lista.');

            return;
        }

        foreach ($item as $index => $medication) {
            if (! is_array($medication) || ! is_string($medication['name'] ?? null) || $medication['name'] === '') {
                $fail("O medicamento na posição {$index} precisa de um nome.");

                continue;
            }

            $this->validateMedicationOptionalFields($index, $medication, $fail);
        }
    }

    /**
     * @param  array<string, mixed>  $medication
     */
    private function validateMedicationOptionalFields(int|string $index, array $medication, Closure $fail): void
    {
        foreach (['dosage', 'frequency'] as $field) {
            if (! array_key_exists($field, $medication)) {
                continue;
            }

            $fieldValue = $medication[$field];
            if (! is_string($fieldValue) || mb_strlen($fieldValue) > self::MAX_TEXT_LENGTH) {
                $fail("O campo \"{$field}\" do medicamento na posição {$index} é inválido.");
            }
        }

        $allowedKeys = ['name', 'dosage', 'frequency'];
        foreach (array_keys($medication) as $medicationKey) {
            if (! in_array($medicationKey, $allowedKeys, true)) {
                $fail("O campo \"{$medicationKey}\" não é aceito no medicamento na posição {$index}.");
            }
        }
    }

    /**
     * `physical_activity` — objeto `{does, type, weekly_frequency, daily_walk}`, mesmos slugs
     * de `UpdatePetRequest` (`does_exercise`, `exercise_types`, `exercise_frequency`,
     * `daily_walk`).
     */
    private function validatePhysicalActivity(mixed $item, Closure $fail): void
    {
        if (! is_array($item)) {
            $fail('"physical_activity" deve ser um objeto.');

            return;
        }

        $allowedKeys = ['does', 'type', 'weekly_frequency', 'daily_walk'];
        foreach (array_keys($item) as $key) {
            if (! in_array($key, $allowedKeys, true)) {
                $fail("O campo \"{$key}\" não é aceito em \"physical_activity\".");
            }
        }

        if (array_key_exists('does', $item) && ! in_array($item['does'], ['yes', 'no'], true)) {
            $fail('O campo "does" de "physical_activity" deve ser "yes" ou "no".');
        }

        if (array_key_exists('type', $item)) {
            $this->validateStringList('physical_activity.type', $item['type'], $fail);
        }

        if (array_key_exists('weekly_frequency', $item)
            && ! in_array($item['weekly_frequency'], self::EXERCISE_FREQUENCY_VALUES, true)) {
            $fail('O valor informado para "weekly_frequency" não é válido.');
        }

        if (array_key_exists('daily_walk', $item) && ! is_bool($item['daily_walk'])) {
            $fail('O campo "daily_walk" de "physical_activity" deve ser verdadeiro ou falso.');
        }
    }
}
