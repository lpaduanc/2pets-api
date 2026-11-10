<?php

namespace Tests\Unit\Documents;

use App\DataTransferObjects\Cnpj;
use App\Support\TaxpayerDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CNPJ alfanumérico (IN RFB 2.229/2024) — Receita Federal, "Perguntas e respostas — CNPJ
 * alfanumérico" (pergunta 14) e NT Conjunta DFe 2025.001 v1.00, item 2: 12 posições letra/dígito +
 * 2 DVs numéricos, módulo 11 com cada caractere valendo ASCII − 48.
 */
class CnpjTest extends TestCase
{
    /**
     * Exemplo oficial da Receita: 12.ABC.345/01DE-35. Conta feita à mão:
     *
     *   caracteres  1  2  A  B  C  3  4  5  0  1  D  E
     *   ASCII − 48  1  2 17 18 19  3  4  5  0  1 20 21
     *   pesos DV1   5  4  3  2  9  8  7  6  5  4  3  2
     *   produtos    5  8 51 36 171 24 28 30 0  4 60 42  → soma 459; 459 mod 11 = 8 → DV1 = 11 − 8 = 3
     *
     *   + DV1 (3), pesos DV2  6  5  4  3  2  9  8  7  6  5  4  3  2
     *   produtos    6 10 68 54 38 27 32 35  0  5 80 63  6 → soma 424; 424 mod 11 = 6 → DV2 = 11 − 6 = 5
     */
    public function test_the_official_alphanumeric_example_has_valid_check_digits(): void
    {
        $cnpj = Cnpj::tryParse('12.ABC.345/01DE-35');

        $this->assertNotNull($cnpj);
        $this->assertSame('12ABC34501DE35', $cnpj->digits);
        $this->assertTrue($cnpj->hasValidCheckDigits());
        $this->assertTrue($cnpj->isAlphanumeric());
        $this->assertSame('12ABC345', $cnpj->root());
    }

    public function test_numeric_cnpjs_keep_the_same_check_digits(): void
    {
        $cnpj = Cnpj::tryParse('11.222.333/0001-81');

        $this->assertTrue($cnpj?->hasValidCheckDigits());
        $this->assertFalse($cnpj->isAlphanumeric());
        $this->assertSame('11222333', $cnpj->root());
    }

    /** @return array<string, array{0: string}> */
    public static function wrongCheckDigits(): array
    {
        return [
            'alfanumérico com DV trocado' => ['12ABC34501DE36'],
            'numérico com DV trocado' => ['11222333000180'],
            'sequência repetida' => ['00000000000000'],
        ];
    }

    #[DataProvider('wrongCheckDigits')]
    public function test_wrong_check_digits_are_refused(string $value): void
    {
        $this->assertFalse(Cnpj::tryParse($value)?->hasValidCheckDigits());
    }

    public function test_lowercase_and_masks_are_normalized_to_uppercase(): void
    {
        $this->assertSame('12ABC34501DE35', Cnpj::stripMask(' 12.abc.345/01de-35 '));
        $this->assertSame('12ABC34501DE35', Cnpj::normalizeForStorage('12.abc.345/01de-35'));
        $this->assertTrue(Cnpj::tryParse('12abc34501de35')?->hasValidCheckDigits());
    }

    /** @return array<string, array{0: string}> */
    public static function malformed(): array
    {
        return [
            'letra no dígito verificador' => ['12ABC34501DE3A'],
            'curto' => ['12ABC34501DE3'],
            'longo' => ['12ABC34501DE355'],
        ];
    }

    #[DataProvider('malformed')]
    public function test_malformed_values_do_not_parse(string $value): void
    {
        $this->assertNull(Cnpj::tryParse($value));
    }

    public function test_a_field_holding_cpf_or_cnpj_tells_them_apart(): void
    {
        $this->assertSame(TaxpayerDocument::CPF, TaxpayerDocument::type(TaxpayerDocument::normalize('529.982.247-25')));
        $this->assertSame(TaxpayerDocument::CNPJ, TaxpayerDocument::type(TaxpayerDocument::normalize('12.abc.345/01de-35')));
        $this->assertSame(TaxpayerDocument::CNPJ, TaxpayerDocument::type('11222333000181'));
        $this->assertNull(TaxpayerDocument::type('ABC45678901'));
        $this->assertNull(TaxpayerDocument::normalize(' ./- '));
    }
}
