<?php

namespace Tests\Feature\Fiscal;

use App\DataTransferObjects\Fiscal\FiscalInvoicePayload;
use App\DataTransferObjects\Fiscal\FiscalParty;
use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentKind;
use App\Enums\FiscalDocumentOperation;
use App\Enums\FiscalEmissionMode;
use App\Enums\FiscalEnvironment;
use App\Exceptions\Fiscal\NfeXmlInvalid;
use App\Services\Fiscal\Sefaz\NfeXmlBuilder;
use App\Services\Fiscal\Sefaz\SefazCodes;
use App\Support\Fiscal\FiscalAccessKey;
use Carbon\CarbonImmutable;
use NFePHP\Common\Certificate;
use NFePHP\Common\Signer;
use NFePHP\Common\Validator;
use NFePHP\NFe\Tools;
use Tests\Support\Fiscal\TestCertificate;
use Tests\TestCase;

/**
 * Payload neutro → XML 4.00 → assinatura — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfe-nfce.md §5. O XML assinado valida no XSD OFICIAL que vem com o
 * sped-nfe (PL_009_V4), a assinatura confere (digest + RSA com o certificado embutido) e o
 * conteúdo bate com o golden (`tests/Fixtures/golden/sefaz/*.xml`), com só a assinatura e o
 * certificado normalizados (mudam a cada chave de teste gerada).
 */
class SefazNfeXmlTest extends TestCase
{
    private const CNPJ = '11222333000181';

    /** Exemplo oficial da Receita (12.ABC.345/01DE-35). */
    private const ALPHANUMERIC_CNPJ = '12ABC34501DE35';

    private const CSC = 'A1B2C3D4E5F6G7H8I9J0K1L2M3N4O5P6';

    private const GOLDEN_FOLDER = 'tests/Fixtures/golden/sefaz';

    /** São Paulo/SP — cMunFG do emitente. */
    private const CITY_CODE = '3550308';

    public function test_nfce_is_built_signed_valid_against_the_official_xsd_and_matches_the_golden(): void
    {
        $signed = $this->sign($this->build($this->nfcePayload()), 65);

        $this->assertValidAndSigned($signed);
        $this->assertStringContainsString('<tpEmis>1</tpEmis>', $signed);
        $this->assertStringContainsString('<xProd>'.SefazCodes::HOMOLOGATION_NFCE_FIRST_ITEM.'</xProd>', $signed);
        $this->assertStringContainsString('<qrCode>', $signed);
        $this->assertStringNotContainsString('<dest>', $signed);
        $this->assertGolden('nfce-autorizacao.xml', $signed);
    }

    public function test_nfe_with_interstate_recipient_and_normal_regime_matches_the_golden(): void
    {
        $signed = $this->sign($this->build($this->nfePayload()), 55);

        $this->assertValidAndSigned($signed);
        $this->assertStringContainsString('<xNome>'.SefazCodes::HOMOLOGATION_RECIPIENT.'</xNome>', $signed);
        $this->assertStringContainsString('<idDest>2</idDest>', $signed);
        $this->assertStringContainsString('<vNF>119.60</vNF>', $signed);
        $this->assertStringContainsString('<cMun>3304557</cMun>', $signed);
        $this->assertGolden('nfe-autorizacao.xml', $signed);
    }

    public function test_offline_contingency_nfce_carries_tpemis_9_with_entry_time_and_justification(): void
    {
        $payload = $this->nfcePayload(['emissionMode' => FiscalEmissionMode::OFFLINE_CONTINGENCY, 'contingencyReason' => 'SEFAZ fora do ar desde as 10h']);

        $built = $this->build($payload);
        $signed = $this->sign($built, 65);

        $this->assertValidAndSigned($signed);
        $this->assertSame('9', $built->accessKey[34]);
        $this->assertStringContainsString('<dhCont>2026-11-08T10:00:00-03:00</dhCont><xJust>SEFAZ fora do ar desde as 10h</xJust>', $signed);
    }

    public function test_a_total_that_does_not_match_the_items_is_refused_before_signing(): void
    {
        $payload = $this->nfcePayload(['totals' => ['total' => '999.99']]);

        $this->expectException(NfeXmlInvalid::class);
        $this->build($payload);
    }

    public function test_icms_st_on_a_sale_is_refused_locally(): void
    {
        $payload = $this->nfcePayload(['items' => [['csosn' => '201'] + $this->nfceItems()[0]]]);

        $this->expectException(NfeXmlInvalid::class);
        $this->expectExceptionMessage('ICMS-ST');
        $this->build($payload);
    }

    /**
     * CNPJ alfanumérico com o pacote padrão PL_009_V4 (`TCnpj = [0-9]{14}`): nada de XML que a
     * SEFAZ recusaria no schema — rejeição local com o motivo (NT 2026.004).
     */
    public function test_an_alphanumeric_cnpj_is_refused_locally_under_the_pl_009_schema(): void
    {
        config(['fiscal.sefaz.schema' => 'PL_009_V4']);

        $this->expectException(NfeXmlInvalid::class);
        $this->expectExceptionMessage('CNPJ alfanumérico (emitente)');
        $this->build($this->nfePayload(['emitter' => $this->emitter('lucro_presumido', self::ALPHANUMERIC_CNPJ)]));
    }

    /**
     * Com `FISCAL_SEFAZ_SCHEMA=PL_010_V1.30` (pacote do sped-nfe que já aceita letra) a NF-e com
     * emitente e destinatário alfanuméricos monta, assina e valida no XSD; a chave leva as letras
     * nas posições do CNPJ e o DV (ASCII − 48) é o mesmo do sped-common.
     */
    public function test_with_the_pl_010_schema_an_alphanumeric_nfe_is_signed_and_valid(): void
    {
        config(['fiscal.sefaz.schema' => 'PL_010_V1.30']);
        $recipient = new FiscalParty('Pet Shop Alfa Ltda', 'AB12CD34000184', ['street' => 'Rua do Catete', 'number' => '200', 'complement' => null, 'district' => 'Catete', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '22220000'], stateRegistration: '12345678', stateRegistrationType: 'taxpayer');

        $built = $this->build($this->nfePayload(['emitter' => $this->emitter('lucro_presumido', self::ALPHANUMERIC_CNPJ), 'recipient' => $recipient]));
        $signed = $this->sign($built, 55, 'PL_010_V1.30', self::ALPHANUMERIC_CNPJ);

        $this->assertTrue(Validator::isValid($signed, base_path('vendor/nfephp-org/sped-nfe/schemes/PL_010_V1.30/nfe_v4.00.xsd')));
        $this->assertSame(self::ALPHANUMERIC_CNPJ, substr($built->accessKey, 6, 14));
        $this->assertMatchesRegularExpression(FiscalAccessKey::PATTERN, $built->accessKey);
        $this->assertSame(FiscalAccessKey::checkDigit(substr($built->accessKey, 0, 43)), substr($built->accessKey, -1));
        $this->assertStringContainsString('<emit><CNPJ>'.self::ALPHANUMERIC_CNPJ.'</CNPJ>', $signed);
        $this->assertStringContainsString('<dest><CNPJ>AB12CD34000184</CNPJ>', $signed);
    }

    private function build(FiscalInvoicePayload $payload): \App\DataTransferObjects\Fiscal\BuiltNfe
    {
        return app(NfeXmlBuilder::class)->build($payload, CarbonImmutable::parse('2026-11-08 13:00:00', 'UTC'), self::CITY_CODE, 12345670);
    }

    private function sign(\App\DataTransferObjects\Fiscal\BuiltNfe $built, int $model, string $schema = 'PL_009_V4', string $cnpj = self::CNPJ): string
    {
        $tools = new Tools(json_encode([
            'tpAmb' => 2, 'razaosocial' => 'Clinica Veterinaria Teste Ltda', 'cnpj' => $cnpj, 'siglaUF' => 'SP',
            'schemes' => $schema, 'versao' => '4.00', 'CSC' => self::CSC, 'CSCid' => '000001',
        ]), Certificate::readPfx(TestCertificate::pfx($cnpj), TestCertificate::PASSWORD));
        $tools->model($model);

        return $tools->signNFe($built->xml);
    }

    private function assertValidAndSigned(string $signed): void
    {
        $this->assertTrue(Validator::isValid($signed, base_path('vendor/nfephp-org/sped-nfe/schemes/PL_009_V4/nfe_v4.00.xsd')));
        $this->assertTrue(Signer::isSigned($signed, 'infNFe'));
    }

    private function assertGolden(string $file, string $signed): void
    {
        $normalized = preg_replace(['/<SignatureValue>[^<]+<\/SignatureValue>/', '/<X509Certificate>[^<]+<\/X509Certificate>/'], ['<SignatureValue>~</SignatureValue>', '<X509Certificate>~</X509Certificate>'], $signed);
        $path = base_path(self::GOLDEN_FOLDER.'/'.$file);

        $this->assertFileExists($path, 'Golden ausente: gere-o conferindo o XML à mão.');
        $this->assertSame(trim(file_get_contents($path)), trim($normalized));
    }

    /** @param  array<string, mixed>  $overrides */
    private function nfcePayload(array $overrides = []): FiscalInvoicePayload
    {
        $items = $overrides['items'] ?? $this->nfceItems();

        return new FiscalInvoicePayload(
            documentId: 1,
            kind: FiscalDocumentKind::NFCE,
            operation: FiscalDocumentOperation::SALE,
            direction: FiscalDocumentDirection::OUT,
            environment: FiscalEnvironment::HOMOLOGATION,
            emissionMode: $overrides['emissionMode'] ?? FiscalEmissionMode::NORMAL,
            contingencyReason: $overrides['contingencyReason'] ?? null,
            series: '1',
            number: 42,
            emitter: $this->emitter('simples_nacional'),
            recipient: null,
            items: $items,
            totals: ($overrides['totals'] ?? []) + ['products' => '130.00', 'discount' => '13.00', 'other' => '0.00', 'total' => '117.00'],
            references: [],
            payments: [['method' => 'cash', 'amount' => '100.00'], ['method' => 'pix', 'amount' => '17.00']],
            presence: '1',
            finalConsumer: true,
        );
    }

    /** @return list<array<string, mixed>> Simples Nacional, CSOSN 102, sem destaque */
    private function nfceItems(): array
    {
        $base = ['cest' => null, 'unit' => 'UN', 'cfop' => '5102', 'origin' => '0', 'csosn' => '102', 'cst' => null, 'icms_base' => '0.00', 'icms_rate' => '0.0000', 'icms_amount' => '0.00', 'icms_base_reduction_percent' => '0.0000', 'icms_sn_credit_rate' => '0.0000', 'icms_sn_credit_amount' => '0.00', 'fcp_rate' => '0.0000', 'fcp_amount' => '0.00', 'pis_cst' => '49', 'pis_base' => '0.00', 'pis_rate' => '0.0000', 'pis_amount' => '0.00', 'cofins_cst' => '49', 'cofins_base' => '0.00', 'cofins_rate' => '0.0000', 'cofins_amount' => '0.00', 'ipi_amount' => '0.00', 'icms_st_amount' => '0.00', 'benefit_code' => null, 'surcharge_share' => '0.00'];

        return [
            $base + ['line_number' => 1, 'product_id' => 10, 'description' => 'Racao Premium 3kg', 'ncm' => '23091000', 'quantity' => '2.0000', 'unit_price' => '50.0000', 'gross_total' => '100.00', 'discount_share' => '10.00', 'total' => '90.00'],
            $base + ['line_number' => 2, 'product_id' => 11, 'description' => 'Coleira antipulgas', 'ncm' => '42010090', 'quantity' => '1.0000', 'unit_price' => '30.0000', 'gross_total' => '30.00', 'discount_share' => '3.00', 'total' => '27.00'],
        ];
    }

    /** Golden fiscal-item-01 (spec fase4-fiscal-sem-envio §7): CST 00 18%, PIS 0,65%, COFINS 3%, acréscimo 2,60. */
    /** @param  array{emitter?: FiscalParty, recipient?: FiscalParty}  $overrides */
    private function nfePayload(array $overrides = []): FiscalInvoicePayload
    {
        $base = ['cest' => null, 'unit' => 'UN', 'cfop' => '6102', 'origin' => '0', 'csosn' => null, 'cst' => '00', 'icms_rate' => '18.0000', 'icms_base_reduction_percent' => '0.0000', 'icms_sn_credit_rate' => '0.0000', 'icms_sn_credit_amount' => '0.00', 'fcp_rate' => '0.0000', 'fcp_amount' => '0.00', 'pis_cst' => '01', 'pis_rate' => '0.6500', 'cofins_cst' => '01', 'cofins_rate' => '3.0000', 'ipi_amount' => '0.00', 'icms_st_amount' => '0.00', 'benefit_code' => null];
        $items = [
            $base + ['line_number' => 1, 'product_id' => 10, 'description' => 'Racao Premium 3kg', 'ncm' => '23091000', 'quantity' => '2.0000', 'unit_price' => '50.0000', 'gross_total' => '100.00', 'discount_share' => '10.00', 'surcharge_share' => '2.00', 'total' => '92.00', 'icms_base' => '92.00', 'icms_amount' => '16.56', 'pis_base' => '75.44', 'pis_amount' => '0.49', 'cofins_base' => '75.44', 'cofins_amount' => '2.26'],
            $base + ['line_number' => 2, 'product_id' => 11, 'description' => 'Coleira antipulgas', 'ncm' => '42010090', 'quantity' => '1.0000', 'unit_price' => '30.0000', 'gross_total' => '30.00', 'discount_share' => '3.00', 'surcharge_share' => '0.60', 'total' => '27.60', 'icms_base' => '27.60', 'icms_amount' => '4.97', 'pis_base' => '22.63', 'pis_amount' => '0.15', 'cofins_base' => '22.63', 'cofins_amount' => '0.68'],
        ];
        $recipient = new FiscalParty('Pet Shop Revenda Ltda', '33444555000172', ['street' => 'Rua do Catete', 'number' => '200', 'complement' => null, 'district' => 'Catete', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '22220000'], stateRegistration: '12345678', stateRegistrationType: 'taxpayer', email: 'compras@petshop.test');

        return new FiscalInvoicePayload(
            documentId: 2, kind: FiscalDocumentKind::NFE, operation: FiscalDocumentOperation::SALE, direction: FiscalDocumentDirection::OUT,
            environment: FiscalEnvironment::HOMOLOGATION, emissionMode: FiscalEmissionMode::NORMAL, contingencyReason: null, series: '1', number: 7,
            emitter: $overrides['emitter'] ?? $this->emitter('lucro_presumido'), recipient: $overrides['recipient'] ?? $recipient, items: $items,
            totals: ['products' => '130.00', 'discount' => '13.00', 'other' => '2.60', 'total' => '119.60'], references: [],
            payments: [['method' => 'credit_card', 'amount' => '119.60']], presence: '1', finalConsumer: false,
        );
    }

    private function emitter(string $regime, string $cnpj = self::CNPJ): FiscalParty
    {
        return new FiscalParty(
            name: 'Clinica Veterinaria Teste Ltda',
            document: $cnpj,
            address: ['street' => 'Rua Augusta', 'number' => '100', 'complement' => null, 'district' => 'Consolacao', 'city' => 'São Paulo', 'state' => 'SP', 'zip' => '01305000'],
            stateRegistration: '110042490114',
            stateRegistrationType: 'taxpayer',
            taxRegime: $regime,
        );
    }
}
