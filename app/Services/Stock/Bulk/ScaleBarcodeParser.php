<?php

namespace App\Services\Stock\Bulk;

use InvalidArgumentException;

/**
 * Etiqueta EAN-13 da balança (docs/gap-petflow/PLANO.md, 3.3), pura:
 *
 *   [prefixo][código do produto][dígitos livres][valor][dígito verificador]   = 13 dígitos
 *
 * O prefixo é um dos `prefixes` (padrão "2", faixa de uso interno do EAN); o código tem
 * `code_length` dígitos logo depois dele; o valor são os `value_length` dígitos antes do
 * verificador — gramas no modo `weight`, centavos no modo `price`. Dígitos entre o código e o
 * valor (quando o layout da balança tem) são ignorados. O verificador é o módulo 10 do EAN-13
 * (pesos 1 e 3 alternados a partir da esquerda). Casos em `tests/Fixtures/golden/scale.json`.
 */
final class ScaleBarcodeParser
{
    public const DEFAULTS = ['prefixes' => ['2'], 'code_length' => 5, 'value_length' => 6, 'mode' => 'weight'];

    /**
     * @param  array<string, mixed>|null  $settings
     * @return array{prefix: string, scale_code: string, value: int, mode: string}
     */
    public function parse(string $barcode, ?array $settings): array
    {
        $settings = ($settings ?? []) + self::DEFAULTS;

        if (! preg_match('/^\d{13}$/', $barcode)) {
            throw new InvalidArgumentException('O código da balança tem 13 dígitos.');
        }

        if (self::checkDigit(substr($barcode, 0, 12)) !== (int) $barcode[12]) {
            throw new InvalidArgumentException('Dígito verificador inválido: leia a etiqueta de novo.');
        }

        $prefix = collect($settings['prefixes'])->map(fn ($p): string => (string) $p)->first(fn (string $p): bool => str_starts_with($barcode, $p));

        if ($prefix === null) {
            throw new InvalidArgumentException('Este código não é uma etiqueta de balança (prefixo não configurado).');
        }

        $codeLength = (int) $settings['code_length'];
        $valueLength = (int) $settings['value_length'];

        if (strlen($prefix) + $codeLength + $valueLength > 12) {
            throw new InvalidArgumentException('Configuração da balança inválida: prefixo + código + valor passam de 12 dígitos.');
        }

        return [
            'prefix' => $prefix,
            'scale_code' => substr($barcode, strlen($prefix), $codeLength),
            'value' => (int) substr($barcode, 12 - $valueLength, $valueLength),
            'mode' => $settings['mode'] === 'price' ? 'price' : 'weight',
        ];
    }

    /** Dígito verificador EAN-13 dos 12 primeiros dígitos. */
    public static function checkDigit(string $twelve): int
    {
        $sum = 0;

        foreach (str_split($twelve) as $i => $digit) {
            $sum += (int) $digit * ($i % 2 === 0 ? 1 : 3);
        }

        return (10 - $sum % 10) % 10;
    }
}
