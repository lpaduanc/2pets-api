<?php

namespace Tests\Unit;

use App\Support\Csv\CsvFormulaGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * CSV/Formula Injection (revisão de segurança, achado Médio 3) — ponto único de neutralização
 * reaproveitado por `CsvDownloadResponder`, `InsightExportService` e
 * `PriceListService::toCsv()`.
 */
class CsvFormulaGuardTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function dangerousPrefixes(): iterable
    {
        yield 'equals (fórmula clássica)' => ['=HYPERLINK("http://attacker.tld","clique")'];
        yield 'plus' => ['+1234567890'];
        yield 'minus' => ['-cmd|\' /C calc\'!A0'];
        yield 'at (DDE)' => ['@SUM(1+1)'];
        yield 'tab' => ["\tmalicious"];
        yield 'carriage return' => ["\rmalicious"];
    }

    #[DataProvider('dangerousPrefixes')]
    public function test_prefixes_a_dangerous_cell_with_an_apostrophe(string $value): void
    {
        $this->assertSame("'".$value, CsvFormulaGuard::sanitize($value));
    }

    public function test_leaves_a_normal_string_untouched(): void
    {
        $this->assertSame('Maria da Silva', CsvFormulaGuard::sanitize('Maria da Silva'));
    }

    public function test_leaves_an_empty_string_untouched(): void
    {
        $this->assertSame('', CsvFormulaGuard::sanitize(''));
    }

    public function test_leaves_non_string_values_untouched(): void
    {
        $this->assertSame(10, CsvFormulaGuard::sanitize(10));
        $this->assertNull(CsvFormulaGuard::sanitize(null));
        $this->assertSame(3.5, CsvFormulaGuard::sanitize(3.5));
    }
}
