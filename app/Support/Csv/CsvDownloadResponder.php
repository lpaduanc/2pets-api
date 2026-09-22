<?php

namespace App\Support\Csv;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Monta um `StreamedResponse` de CSV a partir de um cabeçalho fixo + linhas já achatadas em
 * array — usado pelos 3 exports de `docs/gap-simplesvet/specs/25-paineis-operacionais-spec.md`
 * (nunca carrega o arquivo inteiro em memória antes de responder).
 */
final class CsvDownloadResponder
{
    /**
     * @param  list<string>  $header
     * @param  iterable<int, list<string|int|float|null>>  $rows
     */
    public function stream(string $filename, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $header);

            foreach ($rows as $row) {
                // CSV/Formula Injection (revisão de segurança, achado Médio 3): todas as
                // colunas destes exports são texto/data controlado por tutor ou profissional
                // (nunca número formatado), então neutralizar toda a linha é seguro aqui.
                fputcsv($handle, array_map(CsvFormulaGuard::sanitize(...), $row));
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
