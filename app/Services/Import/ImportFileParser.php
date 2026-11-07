<?php

namespace App\Services\Import;

/**
 * Parser de CSV com detecção de encoding — item 26 do backlog gap-simplesvet.
 * Windows-1252 é o padrão de exportação de sistema legado brasileiro (decisão de produto já
 * confirmada pelo doc original) — sem detectar isso, acentuação vira caractere corrompido.
 * XLSX (PLANO, Fase 4) passa por `XlsxRowReader` e sai no MESMO formato de linhas do CSV.
 */
final class ImportFileParser
{
    private const CANDIDATE_ENCODINGS = ['UTF-8', 'Windows-1252', 'ISO-8859-1'];

    public function __construct(private readonly XlsxRowReader $xlsx = new XlsxRowReader) {}

    /**
     * `$extension` é a do arquivo enviado (o temporário do upload não tem extensão).
     *
     * @return array{headers: list<string>, rows: list<array<string, string>>}
     */
    public function parse(string $filePath, ?string $extension = null): array
    {
        if (strtolower((string) $extension) === 'xlsx') {
            return $this->parseXlsx($filePath);
        }

        $content = $this->readAsUtf8($filePath);
        $lines = $this->splitLines($content);

        if ($lines === []) {
            return ['headers' => [], 'rows' => []];
        }

        $headers = $this->parseLine(array_shift($lines));

        return ['headers' => $headers, 'rows' => $this->mapRowsToHeaders($headers, $lines)];
    }

    /**
     * @return array{headers: list<string>, rows: list<array<string, string>>}
     */
    private function parseXlsx(string $filePath): array
    {
        $lines = $this->xlsx->read($filePath);

        if ($lines === []) {
            return ['headers' => [], 'rows' => []];
        }

        $headers = array_shift($lines);

        // Colunas só formatadas à direita do cabeçalho não viram coluna (no CSV elas nem existem).
        while ($headers !== [] && end($headers) === '') {
            array_pop($headers);
        }

        return ['headers' => $headers, 'rows' => array_map(fn (array $columns): array => $this->combineWithHeaders($headers, $columns), $lines)];
    }

    private function readAsUtf8(string $filePath): string
    {
        $raw = (string) file_get_contents($filePath);
        $encoding = mb_detect_encoding($raw, self::CANDIDATE_ENCODINGS, true) ?: 'Windows-1252';

        return $encoding === 'UTF-8' ? $raw : mb_convert_encoding($raw, 'UTF-8', $encoding);
    }

    /**
     * @return list<string>
     */
    private function splitLines(string $content): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $content);
        $lines = array_filter(explode("\n", $normalized), fn (string $line): bool => trim($line) !== '');

        return array_values($lines);
    }

    /**
     * @return list<string>
     */
    private function parseLine(string $line): array
    {
        $delimiter = str_contains($line, ';') ? ';' : ',';
        $columns = str_getcsv($line, $delimiter);

        return array_map(fn (?string $column): string => trim((string) $column), $columns);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<string>  $lines
     * @return list<array<string, string>>
     */
    private function mapRowsToHeaders(array $headers, array $lines): array
    {
        return array_map(
            fn (string $line): array => $this->combineWithHeaders($headers, $this->parseLine($line)),
            $lines
        );
    }

    /**
     * @param  list<string>  $headers
     * @param  list<string>  $columns
     * @return array<string, string>
     */
    private function combineWithHeaders(array $headers, array $columns): array
    {
        $row = [];
        foreach ($headers as $index => $header) {
            $row[$header] = $columns[$index] ?? '';
        }

        return $row;
    }
}
