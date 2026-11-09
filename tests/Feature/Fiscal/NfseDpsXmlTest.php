<?php

namespace Tests\Feature\Fiscal;

use App\DataTransferObjects\Fiscal\FiscalInvoicePayload;
use App\DataTransferObjects\Fiscal\FiscalParty;
use App\DataTransferObjects\Fiscal\NfseDpsContext;
use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentKind;
use App\Enums\FiscalDocumentOperation;
use App\Enums\FiscalEmissionMode;
use App\Enums\FiscalEnvironment;
use App\Enums\NfseLayout;
use App\Enums\TaxRegime;
use App\Exceptions\Fiscal\NfeXmlInvalid;
use App\Models\Organization;
use App\Services\Fiscal\Nfse\NfseDpsBuilder;
use App\Services\Fiscal\Nfse\NfseSchemaValidator;
use App\Services\Fiscal\Nfse\NfseTaxationRules;
use App\Services\Fiscal\Nfse\NfseXmlSigner;
use Carbon\CarbonImmutable;
use NFePHP\Common\Certificate;
use NFePHP\Common\Signer;
use Tests\Support\Fiscal\TestCertificate;
use Tests\TestCase;

/**
 * Payload neutro → DPS 1.01 → assinatura — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfse-nacional.md §5. A DPS assinada valida nos DOIS XSDs oficiais
 * (produção 2026-02-09 e produção restrita 2026-10-20), a assinatura confere e o conteúdo bate com
 * o golden (`tests/Fixtures/golden/nfse/`), com só assinatura e certificado normalizados.
 */
class NfseDpsXmlTest extends TestCase
{
    private const CNPJ = '11222333000181';

    private const CITY_CODE = '3550308';

    private const GOLDEN_FOLDER = 'tests/Fixtures/golden/nfse';

    public function test_simples_dps_is_valid_in_both_official_schemas_signed_and_matches_the_golden(): void
    {
        foreach (NfseLayout::cases() as $layout) {
            $signed = $this->signed($this->payload(), $this->context(TaxRegime::SIMPLES_NACIONAL, $layout));

            app(NfseSchemaValidator::class)->validate($signed, $layout, NfseSchemaValidator::DPS);
            $this->assertTrue(Signer::isSigned($signed, 'infDPS'), $layout->value);
            $this->assertGolden("dps-simples-{$layout->value}.xml", $signed);
        }
    }

    public function test_simples_dps_carries_the_regime_rules_of_the_national_layout(): void
    {
        $xml = $this->build($this->payload(), $this->context(TaxRegime::SIMPLES_NACIONAL));

        $this->assertStringContainsString('<prest><CNPJ>11222333000181</CNPJ><IM>98765</IM><regTrib><opSimpNac>3</opSimpNac><regApTribSN>1</regApTribSN><regEspTrib>0</regEspTrib></regTrib></prest>', $xml);
        $this->assertStringNotContainsString('<pAliq>', $xml, 'Município ativo e sem retenção: a alíquota é da Sefin (E0617/E0625/E0635).');
        $this->assertStringNotContainsString('<prest><CNPJ>11222333000181</CNPJ><xNome>', $xml, 'Prestador emitente vai sem nome (E0121).');
        $this->assertStringContainsString('<totTrib><pTotTribSN>6.25</pTotTribSN></totTrib>', $xml);
        $this->assertStringContainsString('<vServ>301.00</vServ></vServPrest><vDescCondIncond><vDescIncond>21.00</vDescIncond></vDescCondIncond>', $xml);
        $this->assertStringContainsString('<toma><CPF>52998224725</CPF><xNome>Tutora Teste</xNome><end><endNac><cMun>3550308</cMun><CEP>01001000</CEP></endNac>', $xml);
    }

    public function test_mei_and_normal_regime_use_their_own_approximate_tax_choice(): void
    {
        $mei = $this->build($this->payload(), $this->context(TaxRegime::MEI));
        $presumed = $this->build($this->payload(), $this->context(TaxRegime::LUCRO_PRESUMIDO));

        $this->assertStringContainsString('<opSimpNac>2</opSimpNac><regEspTrib>0</regEspTrib>', $mei);
        $this->assertStringContainsString('<totTrib><indTotTrib>0</indTotTrib></totTrib>', $mei);
        $this->assertStringContainsString('<opSimpNac>1</opSimpNac><regEspTrib>6</regEspTrib>', $presumed);
        $this->assertStringContainsString('<pTotTrib><pTotTribFed>0.00</pTotTribFed><pTotTribEst>0.00</pTotTribEst><pTotTribMun>5.00</pTotTribMun></pTotTrib>', $presumed);
        foreach ([TaxRegime::MEI, TaxRegime::LUCRO_PRESUMIDO] as $regime) {
            app(NfseSchemaValidator::class)->validate($this->signed($this->payload(), $this->context($regime)), NfseLayout::PRODUCTION_20260209, NfseSchemaValidator::DPS);
        }
    }

    public function test_layouts_differ_only_where_the_official_schemas_differ(): void
    {
        $production = $this->build($this->payload(), $this->context(TaxRegime::SIMPLES_NACIONAL, NfseLayout::PRODUCTION_20260209));
        $reform = $this->build($this->payload(), $this->context(TaxRegime::SIMPLES_NACIONAL, NfseLayout::REFORM_20261020));

        $this->assertStringNotContainsString('<finNFSe>', $production);
        $this->assertStringContainsString('<tpEmit>1</tpEmit><finNFSe>0</finNFSe><cLocEmi>', $reform);
        $this->assertMatchesRegularExpression('/<cTribMun>001<\/cTribMun><xDescServ>[^<]+<\/xDescServ><cNBS>123456789<\/cNBS>/', $production);
        $this->assertMatchesRegularExpression('/<cNBS>123456789<\/cNBS><xDescServ>[^<]+<\/xDescServ>/', $reform);
    }

    public function test_anonymous_consumer_has_no_taker_group(): void
    {
        $xml = $this->build($this->payload(['recipient' => null]), $this->context(TaxRegime::SIMPLES_NACIONAL));

        $this->assertStringNotContainsString('<toma>', $xml);
    }

    public function test_a_cent_of_difference_between_items_and_document_total_is_rejected_not_adjusted(): void
    {
        $this->expectException(NfeXmlInvalid::class);
        $this->expectExceptionMessage('difere do total do documento');

        $this->build($this->payload(['total' => '280.01']), $this->context(TaxRegime::SIMPLES_NACIONAL));
    }

    public function test_service_without_national_code_is_rejected_with_its_name(): void
    {
        $items = $this->items();
        $items[1]['national_tax_code'] = null;

        $this->expectException(NfeXmlInvalid::class);
        $this->expectExceptionMessage('Serviço sem código de tributação nacional (cTribNac) no cadastro: Vacina V10.');

        $this->build($this->payload(['items' => $items]), $this->context(TaxRegime::SIMPLES_NACIONAL));
    }

    public function test_dps_id_follows_the_official_composition(): void
    {
        $this->assertSame('DPS'.'3550308'.'2'.self::CNPJ.'00001'.'000000000000042', $this->context(TaxRegime::SIMPLES_NACIONAL)->dpsId(self::CNPJ));
    }

    private function build(FiscalInvoicePayload $payload, NfseDpsContext $context): string
    {
        return app(NfseDpsBuilder::class)->build($payload, $context);
    }

    private function signed(FiscalInvoicePayload $payload, NfseDpsContext $context): string
    {
        $certificate = Certificate::readPfx(TestCertificate::pfx(self::CNPJ), TestCertificate::PASSWORD);

        return app(NfseXmlSigner::class)->signDps($certificate, $this->build($payload, $context));
    }

    private function assertGolden(string $file, string $signed): void
    {
        $normalized = preg_replace(['/<SignatureValue>[^<]+<\/SignatureValue>/', '/<X509Certificate>[^<]+<\/X509Certificate>/'], ['<SignatureValue>~</SignatureValue>', '<X509Certificate>~</X509Certificate>'], $signed);
        $path = base_path(self::GOLDEN_FOLDER.'/'.$file);

        $this->assertFileExists($path, 'Golden ausente: gere-o conferindo o XML à mão.');
        $this->assertSame(trim((string) file_get_contents($path)), trim((string) $normalized));
    }

    private function context(TaxRegime $regime, NfseLayout $layout = NfseLayout::PRODUCTION_20260209): NfseDpsContext
    {
        $organization = new Organization(['tax_regime' => $regime->value, 'effective_sales_tax_rate' => '6.2450', 'iss_rate' => '5.00', 'special_tax_regime' => '6']);
        $rules = new NfseTaxationRules;

        return new NfseDpsContext(
            layout: $layout,
            environment: FiscalEnvironment::HOMOLOGATION,
            series: '1',
            number: 42,
            issuedAt: CarbonImmutable::parse('2026-11-08 14:30:00', 'America/Sao_Paulo'),
            competence: CarbonImmutable::parse('2026-11-08', 'America/Sao_Paulo'),
            cityCode: self::CITY_CODE,
            recipientCityCode: self::CITY_CODE,
            regime: $rules->regime($organization),
            approximateTaxes: $rules->approximateTaxes($organization),
        );
    }

    /**
     * Consulta 150,00 + vacina 2 × 75,00 (= 300,00), desconto 21,00 rateado (10,50 / 10,50) e
     * acréscimo de cartão 1,00 (0,50 / 0,50): vServ = 300,00 + 1,00 = 301,00; vDescIncond = 21,00;
     * líquido = 280,00 = total do documento.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function payload(array $overrides = []): FiscalInvoicePayload
    {
        return new FiscalInvoicePayload(
            documentId: 1,
            kind: FiscalDocumentKind::NFSE,
            operation: FiscalDocumentOperation::SALE,
            direction: FiscalDocumentDirection::OUT,
            environment: FiscalEnvironment::HOMOLOGATION,
            emissionMode: FiscalEmissionMode::NORMAL,
            contingencyReason: null,
            series: null,
            number: null,
            emitter: new FiscalParty('Clinica Veterinaria Teste Ltda', self::CNPJ, ['street' => 'Rua Augusta', 'number' => '100', 'complement' => null, 'district' => 'Consolacao', 'city' => 'São Paulo', 'state' => 'SP', 'zip' => '01305000'], municipalRegistration: '98765', taxRegime: 'simples_nacional'),
            recipient: array_key_exists('recipient', $overrides) ? $overrides['recipient'] : new FiscalParty('Tutora Teste', '52998224725', ['street' => 'Rua das Flores', 'number' => '10', 'complement' => 'Apto 2', 'district' => 'Centro', 'city' => 'São Paulo', 'state' => 'SP', 'zip' => '01001000'], email: 'tutora@example.com'),
            items: $overrides['items'] ?? $this->items(),
            totals: ['products' => '300.00', 'discount' => '21.00', 'other' => '1.00', 'total' => $overrides['total'] ?? '280.00'],
            references: [],
        );
    }

    /** @return list<array<string, mixed>> */
    private function items(): array
    {
        $codes = ['national_tax_code' => '050101', 'municipal_tax_code' => '001', 'nbs_code' => '123456789'];

        return [
            ['line_number' => 1, 'description' => 'Consulta veterinária', 'quantity' => '1.0000', 'unit_price' => '150.0000', 'gross_total' => '150.00', 'discount_share' => '10.50', 'surcharge_share' => '0.50', 'total' => '140.00'] + $codes,
            ['line_number' => 2, 'description' => 'Vacina V10', 'quantity' => '2.0000', 'unit_price' => '75.0000', 'gross_total' => '150.00', 'discount_share' => '10.50', 'surcharge_share' => '0.50', 'total' => '140.00'] + $codes,
        ];
    }
}
