<?php

namespace App\Services\Import;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Leitura de XLSX para a importação (PLANO, Fase 4): primeira aba, cabeçalho na linha 1, cada
 * célula convertida no MESMO texto que o CSV exportado pelo Excel em pt-BR teria — é isso que
 * os validadores (`BrazilianFormatParser`) já sabem ler:
 *
 *  - data (célula com formato de data; o Excel guarda o número serial) → `dd/mm/aaaa`;
 *  - número com máscara de zeros (`00000-000`, `00000000000`: CEP, CPF, telefone) → o texto
 *    exibido, sem perder o zero à esquerda; célula de texto já chega como digitada;
 *  - outro número → decimal com vírgula e sem milhar (`1250,5`), nunca notação científica;
 *  - verdadeiro/falso → `sim`/`não`; fórmula → o valor calculado.
 *
 * Linhas totalmente vazias são ignoradas, como no CSV (a numeração das linhas segue a mesma
 * regra do CSV, contada depois de descartar as vazias).
 */
final class XlsxRowReader
{
    /** Máscara só de zeros (com separadores): o Excel exibe o zero à esquerda. */
    private const ZERO_MASK = '/^[-.\/\s()"]*0+([-.\/\s()"]*0+)*[-.\/\s()"]*$/';

    /**
     * @return list<list<string>> linhas não vazias, a primeira é o cabeçalho
     */
    public function read(string $filePath): array
    {
        $reader = IOFactory::createReader('Xlsx');
        $sheet = $reader->load($filePath)->getSheet(0);
        $lastColumn = $sheet->getHighestDataColumn();
        $rows = [];

        foreach ($sheet->getRowIterator() as $row) {
            $cells = [];

            foreach ($row->getCellIterator('A', $lastColumn) as $cell) {
                $cells[] = $this->text($cell);
            }

            if (implode('', $cells) !== '') {
                $rows[] = $cells;
            }
        }

        return $rows;
    }

    private function text(Cell $cell): string
    {
        $value = $cell->getCalculatedValue();

        return trim(match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'sim' : 'não',
            is_int($value) || is_float($value) => $this->number($cell, $value),
            default => (string) $value,
        });
    }

    private function number(Cell $cell, int|float $value): string
    {
        $format = (string) $cell->getStyle()->getNumberFormat()->getFormatCode();

        if (ExcelDate::isDateTimeFormatCode($format)) {
            return ExcelDate::excelToDateTimeObject($value)->format('d/m/Y');
        }

        if (preg_match(self::ZERO_MASK, $format) === 1 || $cell->getDataType() === DataType::TYPE_STRING) {
            return (string) $cell->getFormattedValue();
        }

        return self::decimalComma($value);
    }

    /** 1250.5 → "1250,5"; 12345678901 → "12345678901" (sem `E+10`). */
    private static function decimalComma(int|float $value): string
    {
        if (is_int($value) || floor($value) === $value) {
            return number_format($value, 0, '', '');
        }

        return str_replace('.', ',', rtrim(rtrim(sprintf('%.10F', $value), '0'), '.'));
    }
}
