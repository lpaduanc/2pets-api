<?php

namespace Tests\Feature\Fiscal;

use App\Contracts\FiscalProviderGateway;
use App\Enums\FiscalDocumentStatus;
use App\Models\FiscalDocument;
use App\Models\FiscalNumberInvalidation;
use App\Services\Fiscal\FiscalInvoicePayloadBuilder;
use App\Services\Fiscal\LogFiscalProviderGateway;
use App\Support\Fiscal\FiscalAccessKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * `LogFiscalProviderGateway` — driver padrão do ambiente, contrato
 * docs/gap-simplesvet/specs/05-emissao-fiscal-nfe-nfce-nfse-spec.md e docs/gap-petflow/specs/
 * fase4-fiscal-sem-envio.md §5: "a emissão funciona sem nenhuma credencial configurada". Também
 * prova o bind por interface (DIP).
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
        $gateway = app(FiscalProviderGateway::class);

        $this->assertInstanceOf(LogFiscalProviderGateway::class, $gateway);
        $this->assertSame('log', $gateway->name());
        $this->assertTrue($gateway->capabilities()->invalidation);
        $this->assertTrue($gateway->capabilities()->nfceOfflineContingency);
        $this->assertTrue($gateway->capabilities()->returnInvoices);
    }

    public function test_issuing_authorizes_immediately_with_a_valid_44_digit_key_and_logs_the_payload(): void
    {
        Log::spy();

        $document = $this->makeDocument(['series' => '1', 'number' => 7]);
        $result = app(FiscalProviderGateway::class)->issue($document, app(FiscalInvoicePayloadBuilder::class)->build($document));

        $this->assertSame(FiscalDocumentStatus::AUTHORIZED, $result->status);
        $this->assertMatchesRegularExpression('/^\d{44}$/', $result->accessKey);
        $this->assertSame(FiscalAccessKey::checkDigit(substr($result->accessKey, 0, 43)), substr($result->accessKey, -1));
        // Modelo 65 (NFC-e), série 001, número 000000007, emissão normal (1).
        $this->assertSame('65', substr($result->accessKey, 20, 2));
        $this->assertSame('001000000007', substr($result->accessKey, 22, 12));
        $this->assertSame('1', substr($result->accessKey, 34, 1));
        $this->assertNotNull($result->protocol);

        Log::shouldHaveReceived('info')->withArgs(
            fn (string $message, array $context) => $message === 'fiscal.issue (driver=log, simulado)'
                && $context['fiscal_document_id'] === $document->id
                && $context['payload']['number'] === 7
        )->once();
    }

    public function test_offline_contingency_nfce_comes_back_in_contingency_and_is_authorized_after_the_clinic_leaves_it(): void
    {
        $this->clinic->forceFill(['fiscal_contingency_started_at' => now(), 'fiscal_contingency_reason' => 'SEFAZ fora do ar desde 10h'])->save();
        $document = $this->makeDocument(['emission_mode' => 'offline_contingency', 'series' => '1', 'number' => 3]);
        $gateway = app(FiscalProviderGateway::class);

        $issued = $gateway->issue($document, app(FiscalInvoicePayloadBuilder::class)->build($document));
        $this->assertSame(FiscalDocumentStatus::CONTINGENCY, $issued->status);
        $this->assertSame('9', substr($issued->accessKey, 34, 1));
        $this->assertNull($issued->protocol);

        $document->update(['status' => 'contingency', 'access_key' => $issued->accessKey]);
        $this->assertSame(FiscalDocumentStatus::CONTINGENCY, $gateway->checkStatus($document->fresh())->status);

        $this->clinic->forceFill(['fiscal_contingency_started_at' => null])->save();
        $transmitted = $gateway->checkStatus($document->fresh());
        $this->assertSame(FiscalDocumentStatus::AUTHORIZED, $transmitted->status);
        $this->assertNotNull($transmitted->protocol);
    }

    public function test_cancelling_always_succeeds_with_a_protocol(): void
    {
        $document = $this->makeDocument();
        $result = app(FiscalProviderGateway::class)->cancel($document, 'Erro de digitação');

        $this->assertSame(FiscalDocumentStatus::CANCELLED, $result->status);
        $this->assertNotNull($result->protocol);
    }

    public function test_invalidating_a_range_is_authorized_with_a_protocol(): void
    {
        $invalidation = FiscalNumberInvalidation::create([
            'organization_id' => $this->clinic->id,
            'professional_id' => $this->owner->id,
            'kind' => 'nfce',
            'series' => '1',
            'number_from' => 4,
            'number_to' => 5,
            'reason' => 'Falha no sistema ao emitir os números',
        ]);

        $result = app(FiscalProviderGateway::class)->invalidate($invalidation);

        $this->assertSame(FiscalDocumentStatus::AUTHORIZED, $result->status);
        $this->assertNotNull($result->protocol);
    }

    /** @param  array<string, mixed>  $overrides */
    private function makeDocument(array $overrides = []): FiscalDocument
    {
        $product = $this->makeProduct(['price' => 100]);
        $this->openRegisterFor($this->receptionist, 0);
        $cash = $this->paymentMethodId($this->receptionist, 'cash');

        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale'])->assertCreated()->json('data.id');
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $product->id]);
        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $cash, 'amount' => 100])->assertCreated();

        return FiscalDocument::create($overrides + [
            'organization_id' => $this->clinic->id,
            'professional_id' => $this->owner->id,
            'sale_id' => $saleId,
            'kind' => 'nfce',
            'status' => 'pending',
            'total' => 100,
        ]);
    }
}
