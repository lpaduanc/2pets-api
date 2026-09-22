<?php

namespace Tests\Unit\Import;

use App\Services\Import\ImportFileParser;
use Tests\TestCase;

/**
 * Item 26 do backlog gap-simplesvet — critério de aceite: "Importar CSV de 1.000 clientes em
 * Windows-1252 preserva acentuação corretamente."
 */
class ImportFileParserTest extends TestCase
{
    public function test_parses_utf8_csv_with_comma_delimiter(): void
    {
        $path = $this->writeTempFile("nome,cpf\nJoão,12345678909\n");

        $result = (new ImportFileParser)->parse($path);

        $this->assertSame(['nome', 'cpf'], $result['headers']);
        $this->assertSame(['nome' => 'João', 'cpf' => '12345678909'], $result['rows'][0]);
    }

    public function test_parses_semicolon_delimited_csv(): void
    {
        $path = $this->writeTempFile("nome;cpf\nMaria;98765432100\n");

        $result = (new ImportFileParser)->parse($path);

        $this->assertSame(['nome', 'cpf'], $result['headers']);
        $this->assertSame('Maria', $result['rows'][0]['nome']);
    }

    public function test_windows_1252_accented_characters_are_preserved_as_utf8(): void
    {
        $windows1252Content = mb_convert_encoding("nome,cidade\nJosé,São Paulo\n", 'Windows-1252', 'UTF-8');
        $path = $this->writeTempFile($windows1252Content);

        $result = (new ImportFileParser)->parse($path);

        $this->assertSame('José', $result['rows'][0]['nome']);
        $this->assertSame('São Paulo', $result['rows'][0]['cidade']);
    }

    public function test_empty_file_returns_no_headers_or_rows(): void
    {
        $path = $this->writeTempFile('');

        $result = (new ImportFileParser)->parse($path);

        $this->assertSame([], $result['headers']);
        $this->assertSame([], $result['rows']);
    }

    private function writeTempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'import-test-');
        file_put_contents($path, $content);

        return $path;
    }
}
