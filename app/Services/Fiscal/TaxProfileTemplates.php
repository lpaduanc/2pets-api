<?php

namespace App\Services\Fiscal;

/**
 * Modelos SÓ LEITURA para começar um perfil tributário — spec docs/gap-petflow/specs/
 * fase4-fiscal-sem-envio.md §6. Nada disto é gravado nem semeado: a tela copia o modelo para o
 * formulário e o dono confirma COM O CONTADOR. Alíquota de ICMS fica em branco de propósito —
 * depende da UF e do produto.
 */
final class TaxProfileTemplates
{
    public const DISCLAIMER = 'Modelo de partida: confirme cada código e alíquota com seu contador antes de emitir.';

    private const RETURNS_WITHOUT_ST = [
        ['operation' => 'return_from_customer', 'scope' => 'intrastate', 'cfop' => '1202'],
        ['operation' => 'return_from_customer', 'scope' => 'interstate', 'cfop' => '2202'],
        ['operation' => 'return_to_supplier', 'scope' => 'intrastate', 'cfop' => '5202'],
        ['operation' => 'return_to_supplier', 'scope' => 'interstate', 'cfop' => '6202'],
    ];

    private const RETURNS_WITH_ST = [
        ['operation' => 'return_from_customer', 'scope' => 'intrastate', 'cfop' => '1411'],
        ['operation' => 'return_from_customer', 'scope' => 'interstate', 'cfop' => '2411'],
        ['operation' => 'return_to_supplier', 'scope' => 'intrastate', 'cfop' => '5411'],
        ['operation' => 'return_to_supplier', 'scope' => 'interstate', 'cfop' => '6411'],
    ];

    private const SALES_WITHOUT_ST = [
        ['operation' => 'sale_consumer', 'scope' => 'intrastate', 'cfop' => '5102'],
        ['operation' => 'sale_consumer', 'scope' => 'interstate', 'cfop' => '6108'],
        ['operation' => 'sale_resale', 'scope' => 'intrastate', 'cfop' => '5102'],
        ['operation' => 'sale_resale', 'scope' => 'interstate', 'cfop' => '6102'],
    ];

    private const SALES_WITH_ST = [
        ['operation' => 'sale_consumer', 'scope' => 'intrastate', 'cfop' => '5405'],
        ['operation' => 'sale_consumer', 'scope' => 'interstate', 'cfop' => '6404'],
        ['operation' => 'sale_resale', 'scope' => 'intrastate', 'cfop' => '5405'],
        ['operation' => 'sale_resale', 'scope' => 'interstate', 'cfop' => '6404'],
    ];

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return [
            $this->template('simples_revenda', 'Simples Nacional — revenda sem ST', 'simples_nacional', ['codes' => ['icms_csosn' => '102', 'pis_cst' => '49', 'cofins_cst' => '49'], 'cfops' => [...self::SALES_WITHOUT_ST, ...self::RETURNS_WITHOUT_ST]]),
            $this->template('simples_st', 'Simples Nacional — mercadoria com ST já retida', 'simples_nacional', ['codes' => ['icms_csosn' => '500', 'pis_cst' => '49', 'cofins_cst' => '49'], 'cfops' => [...self::SALES_WITH_ST, ...self::RETURNS_WITH_ST]]),
            $this->template('presumido_tributado', 'Lucro Presumido — tributado integralmente', 'lucro_presumido', ['codes' => ['icms_cst' => '00', 'pis_cst' => '01', 'pis_rate' => '0.6500', 'cofins_cst' => '01', 'cofins_rate' => '3.0000'], 'cfops' => [...self::SALES_WITHOUT_ST, ...self::RETURNS_WITHOUT_ST]]),
            $this->template('presumido_st', 'Lucro Presumido — mercadoria com ST já retida', 'lucro_presumido', ['codes' => ['icms_cst' => '60', 'pis_cst' => '01', 'pis_rate' => '0.6500', 'cofins_cst' => '01', 'cofins_rate' => '3.0000'], 'cfops' => [...self::SALES_WITH_ST, ...self::RETURNS_WITH_ST]]),
        ];
    }

    /**
     * @param  array{codes: array<string, string>, cfops: list<array{operation: string, scope: string, cfop: string}>}  $content
     * @return array<string, mixed>
     */
    private function template(string $key, string $name, string $regime, array $content): array
    {
        return [
            'key' => $key,
            'name' => $name,
            'tax_regime' => $regime,
            'disclaimer' => self::DISCLAIMER,
            'values' => $content['codes'] + [
                'origin' => '0', 'icms_csosn' => null, 'icms_cst' => null, 'icms_rate' => null,
                'icms_base_reduction_percent' => '0.0000', 'icms_sn_credit_rate' => '0.0000', 'fcp_rate' => '0.0000',
                'pis_rate' => '0.0000', 'cofins_rate' => '0.0000',
            ],
            'cfops' => $content['cfops'],
        ];
    }
}
