<?php

namespace Tests\Unit\Import;

use App\Models\DataImport;
use App\Services\Import\BrazilianFormatParser;
use App\Services\Import\ClientImportValidator;
use Tests\TestCase;

/**
 * Item 26 do backlog gap-simplesvet — regra 3: linha inválida não trava o lote, este
 * validador só reporta o resultado. Critério de aceite: "CPF em formatos diferentes
 * normaliza igual via DocumentNumber::normalizeForStorage() e detecta duplicado."
 */
class ClientImportValidatorTest extends TestCase
{
    private ClientImportValidator $validator;

    private DataImport $import;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new ClientImportValidator(new BrazilianFormatParser);
        $this->import = new DataImport;
    }

    public function test_valid_row_normalizes_masked_cpf_to_digits_only(): void
    {
        $result = $this->validator->validate([
            'name' => 'Ana Silva',
            'cpf' => '123.456.789-09',
            'email' => 'ana@example.com',
            'phone' => '(11) 99999-8888',
        ], $this->import);

        $this->assertTrue($result['valid']);
        $this->assertSame('12345678909', $result['normalized']['cpf']);
        $this->assertSame('11999998888', $result['normalized']['phone']);
    }

    public function test_row_without_name_is_invalid(): void
    {
        $result = $this->validator->validate(['name' => ''], $this->import);

        $this->assertFalse($result['valid']);
        $this->assertContains('Nome é obrigatório.', $result['errors']);
    }

    public function test_cpf_with_wrong_digit_count_is_invalid(): void
    {
        $result = $this->validator->validate(['name' => 'Ana', 'cpf' => '123'], $this->import);

        $this->assertFalse($result['valid']);
        $this->assertContains('CPF inválido.', $result['errors']);
    }

    public function test_invalid_email_format_is_rejected(): void
    {
        $result = $this->validator->validate(['name' => 'Ana', 'email' => 'não-é-email'], $this->import);

        $this->assertFalse($result['valid']);
        $this->assertContains('E-mail inválido.', $result['errors']);
    }

    public function test_row_without_cpf_or_email_is_still_valid(): void
    {
        $result = $this->validator->validate(['name' => 'Ana'], $this->import);

        $this->assertTrue($result['valid']);
        $this->assertNull($result['normalized']['cpf']);
        $this->assertNull($result['normalized']['email']);
    }

    public function test_birth_date_in_brazilian_format_is_normalized_to_iso(): void
    {
        $result = $this->validator->validate(['name' => 'Ana', 'birth_date' => '05/03/1990'], $this->import);

        $this->assertTrue($result['valid']);
        $this->assertSame('1990-03-05', $result['normalized']['birth_date']);
    }
}
