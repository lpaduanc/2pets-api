<?php

namespace App\Support\Fiscal;

use App\Enums\FiscalDocumentKind;
use App\Enums\FiscalEmissionMode;
use Carbon\CarbonInterface;

/**
 * Chave de acesso de 44 dígitos da NF-e/NFC-e (Manual de Orientação do Contribuinte, 4.00):
 * cUF(2) AAMM(4) CNPJ(14) mod(2) série(3) nNF(9) tpEmis(1) cNF(8) cDV(1), dígito verificador
 * módulo 11. Usada pelo driver simulado e reaproveitável pelo driver próprio da SEFAZ.
 */
final class FiscalAccessKey
{
    public const LENGTH = 44;

    /** Código IBGE de cada UF (cUF). */
    private const STATE_CODES = [
        'RO' => '11', 'AC' => '12', 'AM' => '13', 'RR' => '14', 'PA' => '15', 'AP' => '16', 'TO' => '17',
        'MA' => '21', 'PI' => '22', 'CE' => '23', 'RN' => '24', 'PB' => '25', 'PE' => '26', 'AL' => '27',
        'SE' => '28', 'BA' => '29', 'MG' => '31', 'ES' => '32', 'RJ' => '33', 'SP' => '35', 'PR' => '41',
        'SC' => '42', 'RS' => '43', 'MS' => '50', 'MT' => '51', 'GO' => '52', 'DF' => '53',
    ];

    private const MODELS = [FiscalDocumentKind::NFE->value => '55', FiscalDocumentKind::NFCE->value => '65'];

    private const EMISSION_TYPES = [FiscalEmissionMode::NORMAL->value => '1', FiscalEmissionMode::OFFLINE_CONTINGENCY->value => '9'];

    private const UNKNOWN_STATE_CODE = '00';

    /** @param  array{state: string|null, issued_at: CarbonInterface, document: string|null, kind: FiscalDocumentKind, series: string|null, number: int|null, mode: FiscalEmissionMode, code: int}  $parts */
    public static function compose(array $parts): string
    {
        $body = (self::STATE_CODES[strtoupper((string) $parts['state'])] ?? self::UNKNOWN_STATE_CODE)
            .$parts['issued_at']->format('ym')
            .str_pad(substr((string) $parts['document'], 0, 14), 14, '0', STR_PAD_LEFT)
            .(self::MODELS[$parts['kind']->value] ?? '00')
            .str_pad((string) ($parts['series'] ?? '0'), 3, '0', STR_PAD_LEFT)
            .str_pad((string) ($parts['number'] ?? 0), 9, '0', STR_PAD_LEFT)
            .self::EMISSION_TYPES[$parts['mode']->value]
            .str_pad((string) $parts['code'], 8, '0', STR_PAD_LEFT);

        return $body.self::checkDigit($body);
    }

    /** Módulo 11 com pesos 2..9 da direita para a esquerda; resto 0 ou 1 ⇒ dígito 0. */
    public static function checkDigit(string $body): string
    {
        $sum = 0;
        $weight = 2;

        foreach (array_reverse(str_split($body)) as $digit) {
            $sum += (int) $digit * $weight;
            $weight = $weight === 9 ? 2 : $weight + 1;
        }

        $remainder = $sum % 11;

        return (string) ($remainder < 2 ? 0 : 11 - $remainder);
    }
}
