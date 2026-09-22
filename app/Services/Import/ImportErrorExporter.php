<?php

namespace App\Services\Import;

use App\Models\DataImport;

/**
 * CSV das linhas com erro de uma importação (item 26 do backlog gap-simplesvet, regra 3: linha
 * inválida não trava o lote — o usuário reenvia só as que falharam).
 */
final class ImportErrorExporter
{
    public function toCsv(DataImport $import): string
    {
        $lines = ['linha,erro'];

        foreach ($import->rows()->invalid()->orderBy('row_number')->cursor() as $row) {
            $lines[] = $this->formatLine($row->row_number, $row->errors ?? []);
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<string>  $errors
     */
    private function formatLine(int $rowNumber, array $errors): string
    {
        $message = str_replace('"', '""', implode('; ', $errors));

        return sprintf('%d,"%s"', $rowNumber, $message);
    }
}
