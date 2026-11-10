<?php

namespace Tests\Unit\Fiscal;

use App\Enums\FiscalDocumentKind;
use App\Enums\FiscalEmissionMode;
use App\Support\Fiscal\FiscalAccessKey;
use Carbon\CarbonImmutable;
use NFePHP\Common\Keys;
use PHPUnit\Framework\TestCase;

/**
 * Chave de acesso com CNPJ alfanumérico — NT Conjunta DFe 2025.001 v1.00, item 5: padrão
 * `[0-9]{6}[A-Z0-9]{12}[0-9]{26}` e DV módulo 11 com cada caractere valendo ASCII − 48.
 */
class AlphanumericAccessKeyTest extends TestCase
{
    public function test_the_key_carries_the_letters_and_its_check_digit_matches_sped_common(): void
    {
        $key = FiscalAccessKey::compose([
            'state' => 'SP',
            'issued_at' => CarbonImmutable::parse('2026-11-10'),
            'document' => '12ABC34501DE35',
            'kind' => FiscalDocumentKind::NFE,
            'series' => '1',
            'number' => 123,
            'mode' => FiscalEmissionMode::NORMAL,
            'code' => 12345678,
        ]);

        // 35 2611 12ABC34501DE35 55 001 000000123 1 12345678 + DV 8 (o sped-common chega ao mesmo).
        $this->assertSame('35261112ABC34501DE35550010000001231123456788', $key);
        $this->assertSame(Keys::build(35, 26, 11, '12ABC34501DE35', 55, 1, 123, 1, 12345678), $key);
        $this->assertTrue(Keys::isValid($key));
        $this->assertTrue(FiscalAccessKey::isWellFormed($key));
    }

    public function test_numeric_keys_keep_the_same_check_digit(): void
    {
        $this->assertSame('5', FiscalAccessKey::checkDigit('5206043300991100250655012000000780026730161'));
    }

    public function test_pasted_keys_are_normalized_and_letters_out_of_the_cnpj_positions_are_refused(): void
    {
        $this->assertSame('35261112ABC34501DE35550010000001231123456788', FiscalAccessKey::normalize('3526 1112 abc3 4501 de35 5500 1000 0001 2311 2345 6788'));
        $this->assertFalse(FiscalAccessKey::isWellFormed('35261112ABC34501DE3555001000000123112345678X'));
    }
}
