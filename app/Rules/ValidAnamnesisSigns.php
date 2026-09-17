<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Valida `medical_records.anamnesis_signs` (contrato
 * docs/atendimento-veterinario/05-contrato-consulta-sem-digitacao.md §3):
 *   - lista de objetos, cada um com `sign` obrigatório dentre os 43 slugs válidos;
 *   - `onset`/`evolution`/`intensity`/`notes` valem para qualquer sinal;
 *   - `frequency_per_day` só é aceito para os sinais episódicos (§3.2, "secundários");
 *   - o modificador específico de cada sinal (`content`, `consistency`, `limb`, `laterality`)
 *     só é aceito no sinal a que pertence — mandar `limb` num item de `vomiting` é erro, não
 *     campo ignorado silenciosamente.
 */
final class ValidAnamnesisSigns implements ValidationRule
{
    private const UNIVERSAL_KEYS = ['sign', 'onset', 'evolution', 'intensity', 'notes'];

    private const MAX_NOTES_LENGTH = 500;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            $fail('A anamnese deve ser uma lista de sinais.');

            return;
        }

        foreach ($value as $index => $item) {
            $this->validateItem($index, $item, $fail);
        }
    }

    private function validateItem(int|string $index, mixed $item, Closure $fail): void
    {
        $sign = is_array($item) ? ($item['sign'] ?? null) : null;

        if (! is_string($sign) || $sign === '') {
            $fail("O item {$index} da anamnese precisa informar o sinal (\"sign\").");

            return;
        }

        if (! in_array($sign, config('clinical-parameters.anamnesis_signs.valid_signs'), true)) {
            $fail("O sinal \"{$sign}\" não é válido.");

            return;
        }

        $this->validateFields($sign, $item, $fail);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function validateFields(string $sign, array $item, Closure $fail): void
    {
        $allowedKeys = $this->allowedKeysFor($sign);

        foreach (array_keys($item) as $key) {
            if (! in_array($key, $allowedKeys, true)) {
                $fail("O campo \"{$key}\" não é válido para o sinal \"{$sign}\".");
            }
        }

        $this->validateKnownValues($item, $fail);
    }

    /**
     * @return list<string>
     */
    private function allowedKeysFor(string $sign): array
    {
        $config = config('clinical-parameters.anamnesis_signs');
        $keys = self::UNIVERSAL_KEYS;

        if (in_array($sign, $config['frequency_signs'], true)) {
            $keys[] = 'frequency_per_day';
        }

        $specific = $config['specific_modifiers'][$sign] ?? null;
        if ($specific !== null) {
            $keys[] = $specific['field'];
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function validateKnownValues(array $item, Closure $fail): void
    {
        $config = config('clinical-parameters.anamnesis_signs');

        $this->validateInList($item, 'onset', $config['onset'], $fail);
        $this->validateInList($item, 'evolution', $config['evolution'], $fail);
        $this->validateInList($item, 'intensity', $config['intensity'], $fail);
        $this->validateFrequency($item, $fail);
        $this->validateSpecificModifier($item, $config, $fail);
        $this->validateNotes($item, $fail);
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
            $fail('A observação do sinal deve ser um texto de até '.self::MAX_NOTES_LENGTH.' caracteres.');
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<string>  $options
     */
    private function validateInList(array $item, string $field, array $options, Closure $fail): void
    {
        if (! array_key_exists($field, $item)) {
            return;
        }

        if (! in_array($item[$field], $options, true)) {
            $fail("O valor informado para \"{$field}\" não é válido.");
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function validateFrequency(array $item, Closure $fail): void
    {
        if (! array_key_exists('frequency_per_day', $item)) {
            return;
        }

        if (! is_int($item['frequency_per_day']) || $item['frequency_per_day'] < 0) {
            $fail('A frequência por dia deve ser um número inteiro não negativo.');
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $config
     */
    private function validateSpecificModifier(array $item, array $config, Closure $fail): void
    {
        $sign = $item['sign'];
        $specific = $config['specific_modifiers'][$sign] ?? null;

        if ($specific === null || ! array_key_exists($specific['field'], $item)) {
            return;
        }

        if (! in_array($item[$specific['field']], $specific['values'], true)) {
            $fail("O valor informado para \"{$specific['field']}\" não é válido.");
        }
    }
}
