<?php

namespace Tests\Feature\Fiscal;

use App\Contracts\FiscalProviderGateway;
use App\Enums\FiscalDocumentStatus;
use App\Models\FiscalDocument;
use App\Services\Fiscal\LogFiscalProviderGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * `LogFiscalProviderGateway` — driver padrão do ambiente, contrato
 * docs/gap-simplesvet/specs/05-emissao-fiscal-nfe-nfce-nfse-spec.md: "a emissão funciona sem
 * nenhuma credencial configurada". Também prova o bind por interface (DIP): `app()` resolve o
 * contrato para o driver fake sem nenhuma configuração extra no ambiente de teste.
 */
class FiscalProviderGatewayTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildClinic();
    }

    public function test_the_container_resolves_the_interface_to_the_log_driver_by_default(): void
    {
        $this->assertInstanceOf(LogFiscalProviderGateway::class, app(FiscalProviderGateway::class));
    }

    public function test_issuing_authorizes_immediately_with_a_fake_access_key_and_logs_the_payload(): void
    {
        Log::spy();

        $document = $this->makeDocument();
        $result = app(FiscalProviderGateway::class)->issue($document);

        $this->assertSame(FiscalDocumentStatus::AUTHORIZED, $result->status);
        $this->assertNotNull($result->accessKey);
        $this->assertStringStartsWith('LOG-nfce-', $result->accessKey);

        Log::shouldHaveReceived('info')->withArgs(
            fn (string $message, array $context) => $message === 'fiscal.issue (driver=log, simulado)'
                && $context['fiscal_document_id'] === $document->id
        )->once();
    }

    public function test_cancelling_always_succeeds_with_a_protocol(): void
    {
        $document = $this->makeDocument();
        $result = app(FiscalProviderGateway::class)->cancel($document, 'Erro de digitação');

        $this->assertSame(FiscalDocumentStatus::CANCELLED, $result->status);
        $this->assertNotNull($result->protocol);
    }

    private function makeDocument(): FiscalDocument
    {
        $product = $this->makeProduct(['price' => 100]);
        $this->openRegisterFor($this->receptionist, 0);
        $cash = $this->paymentMethodId($this->receptionist, 'cash');

        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale'])->assertCreated()->json('data.id');
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $product->id]);
        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $cash, 'amount' => 100])->assertCreated();

        return FiscalDocument::create([
            'organization_id' => $this->clinic->id,
            'professional_id' => $this->owner->id,
            'sale_id' => $saleId,
            'kind' => 'nfce',
            'status' => 'pending',
            'total' => 100,
        ]);
    }
}
