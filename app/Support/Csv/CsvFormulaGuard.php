<?php

namespace App\Support\Csv;

/**
 * Neutraliza CSV/Formula Injection (OWASP, CWE-1236) — ponto único reaproveitado por
 * `CsvDownloadResponder`, `InsightExportService` e `PriceListService::toCsv()` (revisão de
 * segurança, achado Médio 3). Qualquer célula de TEXTO livre (nome/telefone/e-mail de
 * cliente, descrição de produto) que comece com `=`, `+`, `-`, `@`, tab ou CR é tratada como
 * fórmula pelo Excel/LibreOffice/Google Sheets ao abrir o arquivo — prefixar com apóstrofo
 * força a célula a texto sem alterar o valor exibido.
 *
 * Uso restrito a campos de texto controlados por tutor/profissional: NUNCA aplicar em coluna
 * numérica já formatada (preço, quantidade, desconto) — um total negativo legítimo
 * (`-10,50`) não pode virar texto sem valor numérico.
 */
final class CsvFormulaGuard
{
    private const DANGEROUS_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    public static function sanitize(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        return self::startsWithDangerousPrefix($value) ? "'".$value : $value;
    }

    private static function startsWithDangerousPrefix(string $value): bool
    {
        foreach (self::DANGEROUS_PREFIXES as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
