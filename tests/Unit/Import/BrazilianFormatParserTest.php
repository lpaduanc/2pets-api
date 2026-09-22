<?php

namespace Tests\Unit\Import;

use App\Services\Import\BrazilianFormatParser;
use Tests\TestCase;

/**
 * Item 26 do backlog gap-simplesvet — critérios de aceite: "05/03/2026 é lido como 5 de março,
 * não 3 de maio" e "1.234,56 é lido como 1234.56".
 */
class BrazilianFormatParserTest extends TestCase
{
    private BrazilianFormatParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new BrazilianFormatParser;
    }

    public function test_parses_brazilian_date_as_day_month_year(): void
    {
        $this->assertSame('2026-03-05', $this->parser->parseDate('05/03/2026'));
    }

    public function test_parses_iso_date_unchanged(): void
    {
        $this->assertSame('2026-03-05', $this->parser->parseDate('2026-03-05'));
    }

    public function test_invalid_date_returns_null(): void
    {
        $this->assertNull($this->parser->parseDate('não é uma data'));
    }

    public function test_empty_date_returns_null(): void
    {
        $this->assertNull($this->parser->parseDate(''));
    }

    public function test_parses_decimal_with_thousands_separator(): void
    {
        $this->assertSame(1234.56, $this->parser->parseDecimal('1.234,56'));
    }

    public function test_parses_decimal_without_thousands_separator(): void
    {
        $this->assertSame(12.5, $this->parser->parseDecimal('12,5'));
    }

    public function test_invalid_decimal_returns_null(): void
    {
        $this->assertNull($this->parser->parseDecimal('abc'));
    }
}
