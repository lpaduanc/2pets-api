<?php

namespace App\Support\Fiscal;

/**
 * Tabelas de código da NF-e 4.00 que o perfil tributário aceita e quais delas DESTACAM imposto —
 * spec docs/gap-petflow/specs/fase4-fiscal-sem-envio.md §3.2. Lista fechada no código (não
 * cadastro): mudam só com nota técnica da SEFAZ.
 */
final class FiscalTaxCodes
{
    /** CSOSN (Simples Nacional / MEI). */
    public const CSOSN = ['101', '102', '103', '201', '202', '203', '300', '400', '500', '900'];

    /** CST do ICMS (Lucro Presumido / Real). */
    public const ICMS_CST = ['00', '10', '20', '30', '40', '41', '50', '51', '60', '70', '90'];

    /** CST de PIS e COFINS. */
    public const PIS_COFINS_CST = [
        '01', '02', '03', '04', '05', '06', '07', '08', '09',
        '49', '50', '51', '52', '53', '54', '55', '56',
        '60', '61', '62', '63', '64', '65', '66', '67',
        '70', '71', '72', '73', '74', '75', '98', '99',
    ];

    /** CST que destacam ICMS próprio no item (base × alíquota). */
    public const ICMS_HIGHLIGHT_CST = ['00', '10', '20', '51', '70', '90'];

    /** CSOSN que destacam ICMS próprio (o Simples só destaca nesses). */
    public const ICMS_HIGHLIGHT_CSOSN = ['201', '202', '203', '900'];

    /** CSOSN que informam o crédito do Simples (pCredSN/vCredICMSSN, LC 123 art. 23). */
    public const SN_CREDIT_CSOSN = ['101', '201'];

    public static function highlightsIcms(?string $cst, ?string $csosn): bool
    {
        return in_array($cst, self::ICMS_HIGHLIGHT_CST, true) || in_array($csosn, self::ICMS_HIGHLIGHT_CSOSN, true);
    }

    public static function informsSimplesCredit(?string $csosn): bool
    {
        return in_array($csosn, self::SN_CREDIT_CSOSN, true);
    }
}
