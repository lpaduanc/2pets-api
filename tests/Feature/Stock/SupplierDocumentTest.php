<?php

namespace Tests\Feature\Stock;

use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Stock\Concerns\BuildsStockFixtures;
use Tests\TestCase;

/**
 * Documento do fornecedor (CPF ou CNPJ) — CNPJ alfanumérico (IN RFB 2.229/2024): letra é parte do
 * número, então nada de "só dígitos". 11 dígitos = CPF; 14 caracteres no formato do CNPJ = CNPJ.
 */
class SupplierDocumentTest extends TestCase
{
    use BuildsStockFixtures;
    use RefreshDatabase;

    private const URL = '/api/professional/suppliers';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildStockClinic();
    }

    public function test_an_alphanumeric_cnpj_is_stored_uppercase_without_mask_and_found_by_search(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson(self::URL, ['legal_name' => 'Distribuidora Alfa', 'document' => '12.abc.345/01de-35'])
            ->assertCreated()
            ->assertJsonPath('data.document', '12ABC34501DE35');

        $this->getJson(self::URL.'?search=12.ABC.345')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.document', '12ABC34501DE35');
    }

    public function test_numeric_cnpj_and_cpf_keep_working(): void
    {
        $this->actingAs($this->owner, 'sanctum');

        $this->postJson(self::URL, ['legal_name' => 'Distribuidora Numérica', 'document' => '11.222.333/0001-81'])
            ->assertCreated()->assertJsonPath('data.document', '11222333000181');
        $this->postJson(self::URL, ['legal_name' => 'Produtor Rural', 'document' => '529.982.247-25'])
            ->assertCreated()->assertJsonPath('data.document', '52998224725');
    }

    public function test_documents_out_of_both_formats_are_refused(): void
    {
        $this->actingAs($this->owner, 'sanctum');

        foreach (['12ABC34501DE3A', 'ABC45678901', '1234567890123'] as $document) {
            $this->postJson(self::URL, ['legal_name' => 'Fornecedor', 'document' => $document])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('document');
        }

        $this->assertSame(0, Supplier::count());
    }
}
