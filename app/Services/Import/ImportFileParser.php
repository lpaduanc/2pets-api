<?php

namespace App\Services\Import;

/**
 * Parser de CSV com detecção de encoding — item 26 do backlog gap-simplesvet.
 * Windows-1252 é o padrão de exportação de sistema legado brasileiro (decisão de produto já
 * confirmada pelo doc original) — sem detectar isso, acentuação vira caractere corrompido.
 * XLSX fica fora desta rodada (nenhuma lib de planilha instalada, ver spec §Já existe).
 */
final class ImportFileParser
{
    private const CANDIDATE_ENCODINGS = ['UTF-8', 'Windows-1252', 'ISO-8859-1'];

    /**
     * @return array{headers: list<string>, rows: list<array<string, string>>}
     */
    public function parse(string $filePath): array
    {
        $content = $this->readAsUtf8($filePath);
        $lines = $this->splitLines($content);

        if ($lines === []) {
            return ['headers' => [], 'rows' => []];
        }

        $headers = $this->parseLine(array_shift($lines));

        return ['headers' => $headers, 'rows' => $this->mapRowsToHeaders($headers, $lines)];
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
