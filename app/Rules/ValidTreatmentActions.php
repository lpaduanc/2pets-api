<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Valida `medical_records.treatment_actions` (contrato
 * docs/atendimento-veterinario/05-contrato-consulta-sem-digitacao.md §7): lista de objetos
 * `{ category, item, notes }`, onde `category` precisa ser uma das 6 categorias conhecidas e
 * `item` precisa pertencer à lista daquela categoria específica (o mesmo `item` pode existir
 * em categorias diferentes só por coincidência de nome — por isso a checagem é sempre
 * categoria→item, nunca item isolado contra a união de todas as listas).
 */
final class ValidTreatmentActions implements ValidationRule
{
    private const MAX_NOTES_LENGTH = 500;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            $fail('A conduta deve ser uma lista.');

            return;
        }

        foreach ($value as $index => $item) {
            $this->validateItem($index, $item, $fail);
        }
    }

    private function validateItem(int|string $index, mixed $item, Closure $fail): void
    {
        if (! is_array($item)) {
            $fail("O item {$index} da conduta é inválido.");

            return;
        }

        $categories = config('clinical-parameters.treatment_actions');
        $category = $item['category'] ?? null;

        if (! is_string($category) || ! array_key_exists($category, $categories)) {
            $fail("A categoria de conduta \"{$category}\" não é válida.");

            return;
        }

        $this->validateActionItem($category, $item, $categories[$category], $fail);
        $this->validateNotes($item, $fail);
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<string>  $validItems
     */
    private function validateActionItem(string $category, array $item, array $validItems, Closure $fail): void
    {
        $action = $item['item'] ?? null;

        if (! is_string($action) || ! in_array($action, $validItems, true)) {
            $fail("O item \"{$action}\" não é válido para a categoria \"{$category}\".");
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function validateNotes(array $item, Closure $fail): void
    {
        if (! array_key_exists('notes', $item)) {
            return;
        }

        if (! is_string($item['notes']) || mb_strlen($item['notes']) > self::MAX_NOTES_LENGTH) {
            $fail('A observação da conduta deve ser um texto de até '.self::MAX_NOTES_LENGTH.' caracteres.');
        }
    }
}
