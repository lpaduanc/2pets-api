<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Valida o formato de `medical_records.physical_exam` (contrato §5.1):
 *   - toda chave precisa ser um sistema conhecido (`config('clinical-parameters.physical_exam_systems')`)
 *     ou a chave especial `notes`;
 *   - o valor de cada sistema precisa estar na lista de opções daquele sistema;
 *   - `notes` é um mapa `sistema => texto livre`, usado quando a opção escolhida pede detalhe
 *     (`other`, `lesion`, `enlarged`, `murmur_*`) — texto livre, sem validação de conteúdo.
 *
 * Toda chave é opcional: ausência de chave = "sistema não avaliado", que o contrato distingue
 * explicitamente de "avaliado e normal" — esta regra não força nenhuma chave a existir.
 */
final class ValidPhysicalExam implements ValidationRule
{
    private const NOTES_KEY = 'notes';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            $fail('O exame físico deve ser um objeto.');

            return;
        }

        $systems = config('clinical-parameters.physical_exam_systems');

        foreach ($value as $system => $option) {
            if ($system === self::NOTES_KEY) {
                $this->validateNotes($option, $fail);

                continue;
            }

            $this->validateSystemOption((string) $system, $option, $systems, $fail);
        }
    }

    /**
     * @param  array<string, list<string>>  $systems
     */
    private function validateSystemOption(string $system, mixed $option, array $systems, Closure $fail): void
    {
        if (! array_key_exists($system, $systems)) {
            $fail("O sistema \"{$system}\" não existe no exame físico.");

            return;
        }

        if (! is_string($option) || ! in_array($option, $systems[$system], true)) {
            $fail("O valor informado para \"{$system}\" não é uma opção válida.");
        }
    }

    private function validateNotes(mixed $notes, Closure $fail): void
    {
        if (! is_array($notes)) {
            $fail('As observações do exame físico devem ser um objeto sistema → texto.');

            return;
        }

        foreach ($notes as $system => $text) {
            if (! is_string($text)) {
                $fail("A observação para \"{$system}\" deve ser texto.");
            }
        }
    }
}
