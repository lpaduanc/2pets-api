<?php

namespace Tests\Feature\Fiscal;

use App\Models\FiscalDocument;
use App\Services\Fiscal\Nfse\NfseNacionalGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use NFePHP\Common\Signer;
use RuntimeException;
use Tests\Feature\Fiscal\Concerns\BuildsNfseScenario;
use Tests\Support\Fiscal\TestCertificate;
use Tests\TestCase;

/**
 * `NfseNacionalGateway` de ponta a ponta pelos endpoints — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfse-nacional.md §6/§7. A Sefin Nacional é o dublê
 * `FakeNfseNacionalTransport` (nunca a real), que confere a DPS no XSD oficial.
 */
class NfseNacionalGatewayTest extends TestCase
{
    use BuildsNfseScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildNfseClinic();
    }

    public function test_service_sale_is_authorized_by_the_national_system_with_xml_and_danfse_stored(): void
    {
        $document = $this->issueSale($this->paidServiceSale([[$this->makeTaxedService(), 1]]))->assertCreated()->json('data.0');

        $this->assertSame(['nfse', 'authorized', NfseNacionalGateway::NAME, true, true], [$document['kind'], $document['status'], $document['provider'], $document['has_xml'], $document['has_pdf']]);
        $this->assertMatchesRegularExpression('/^\d{50}$/', $document['access_key']);
        $this->assertSame([1, '1', 1], [$document['number'], $document['dps_series'], $document['dps_number']]);
        $stored = FiscalDocument::findOrFail($document['id']);
        $this->assertStringEndsWith($document['access_key'].'-nfse.xml', $stored->xml_path);
        $this->assertStringContainsString('<NFSe', Storage::disk('local')->get($stored->xml_path));
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($stored->pdf_path));
        $this->assertStringStartsWith('DPS3550308211222333000181', $stored->provider_id);

        $request = $this->sefin->requestsFor('emission')[0];
        $this->assertSame('https://sefin.producaorestrita.nfse.gov.br/SefinNacional/nfse', $request->url);
        $this->assertSame(['dpsXmlGZipB64'], array_keys($request->json));
        $dps = $this->sefin->xmlFor('emission')[0];
        $this->assertTrue(Signer::isSigned($dps, 'infDPS'));
        $this->assertStringContainsString('<cTribNac>050101</cTribNac><cTribMun>001</cTribMun>', $dps);
        $this->assertStringContainsString('<vServ>150.00</vServ>', $dps);
        $this->assertStringContainsString('<pTotTribSN>6.00</pTotTribSN>', $dps);
        $this->assertStringContainsString('<IM>12345678</IM>', $dps);
    }

    public function test_dps_numbers_follow_the_own_sequence_per_organization(): void
    {
        $service = $this->makeTaxedService();

        $first = $this->issueSale($this->paidServiceSale([[$service, 1]]))->json('data.0');
        $second = $this->issueSale($this->paidServiceSale([[$service, 1]]))->json('data.0');

        $this->assertSame([1, 2], [$first['dps_number'], $second['dps_number']]);
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/professional/fiscal-settings')
            ->assertOk()
            ->assertJsonPath('data.fiscal_series.nfse.next_number', 3);
    }

    public function test_discount_goes_as_unconditional_discount_and_amounts_close_to_the_cent(): void
    {
        $document = $this->issueSale($this->paidServiceSale([[$this->makeTaxedService(['price' => 33.33]), 3]], ['discount' => '10.00']))->json('data.0');

        $this->assertSame('authorized', $document['status']);
        $this->assertEqualsWithDelta(89.99, $document['total'], 0.0001);
        $dps = $this->sefin->xmlFor('emission')[0];
        $this->assertStringContainsString('<vServ>99.99</vServ>', $dps);
        $this->assertStringContainsString('<vDescIncond>10.00</vDescIncond>', $dps);
        $this->assertStringContainsString('Consulta veterinária (3 x R$ 33,33)', $dps);
    }

    public function test_services_with_different_national_codes_become_separate_nfse(): void
    {
        $consultation = $this->makeTaxedService();
        $bath = $this->makeTaxedService(['name' => 'Banho porte médio', 'price' => 60, 'national_tax_code' => '050801', 'municipal_service_code' => null]);

        $documents = $this->issueSale($this->paidServiceSale([[$consultation, 1], [$bath, 1]]))->assertCreated()->json('data');

        $this->assertCount(2, $documents);
        $this->assertEqualsCanonicalizing([150.0, 60.0], array_column($documents, 'total'));
        $this->assertSame(['authorized', 'authorized'], array_column($documents, 'status'));
        $sent = implode('', $this->sefin->xmlFor('emission'));
        $this->assertStringContainsString('<cTribNac>050801</cTribNac>', $sent);
    }

    public function test_rejection_by_the_national_system_keeps_the_codes_and_messages(): void
    {
        $this->sefin->script('emission', 'E0300');

        $document = $this->issueSale($this->paidServiceSale([[$this->makeTaxedService(), 1]]))->assertCreated()->json('data.0');

        $this->assertSame('rejected', $document['status']);
        $this->assertSame('E0300 - Código de tributação nacional inexistente.', $document['rejection_reason']);
        $this->assertFalse($document['has_pdf']);
    }

    public function test_service_without_national_code_is_rejected_locally_without_calling_the_api(): void
    {
        $service = $this->makeTaxedService(['national_tax_code' => null]);

        $document = $this->issueSale($this->paidServiceSale([[$service, 1]]))->assertCreated()->json('data.0');

        $this->assertSame('rejected', $document['status']);
        $this->assertStringContainsString('Serviço sem código de tributação nacional (cTribNac) no cadastro: Consulta veterinária.', $document['rejection_reason']);
        $this->assertSame([], $this->sefin->requests);
    }

    public function test_a_lost_answer_is_retried_with_the_same_dps_and_recovered_without_a_second_nfse(): void
    {
        $this->sefin->script('emission', 'lost');

        $document = $this->issueSale($this->paidServiceSale([[$this->makeTaxedService(), 1]]))->assertCreated()->json('data.0');

        $this->assertSame(['pending', 1, null], [$document['status'], $document['attempts'], $document['rejection_reason']]);
        $this->assertStringContainsString('Sistema Nacional NFS-e', $document['last_error']);
        $pending = FiscalDocument::findOrFail($document['id']);
        $this->assertStringEndsWith('dps-assinada.xml', $pending->xml_path);

        $this->postJson("/api/professional/fiscal-documents/{$document['id']}/sync")->assertOk()->assertJsonPath('data.status', 'authorized');

        [$first, $second] = $this->sefin->xmlFor('emission');
        $this->assertSame($first, $second, 'A retentativa reenvia a MESMA DPS assinada (write-ahead).');
        $this->assertCount(1, $this->sefin->requestsFor('dps'));
        $this->assertCount(1, $this->sefin->requestsFor('nfse'));
        $this->assertSame(1, FiscalDocument::findOrFail($document['id'])->number);
    }

    public function test_server_errors_and_transport_failures_keep_the_document_pending(): void
    {
        $this->sefin->script('emission', 'http:503', new RuntimeException('SSL certificate problem: unable to get local issuer certificate'));
        $service = $this->makeTaxedService();

        $unavailable = $this->issueSale($this->paidServiceSale([[$service, 1]]))->json('data.0');
        $tls = $this->issueSale($this->paidServiceSale([[$service, 1]]))->json('data.0');

        $this->assertSame(['pending', 'pending'], [$unavailable['status'], $tls['status']]);
        $this->assertStringContainsString('HTTP 503', $unavailable['last_error']);
        $this->assertStringContainsString('SSL certificate problem', $tls['last_error']);
    }

    public function test_cancelling_registers_the_101101_event_and_stores_its_xml(): void
    {
        $document = $this->issueSale($this->paidServiceSale([[$this->makeTaxedService(), 1]]))->json('data.0');

        $this->postJson("/api/professional/fiscal-documents/{$document['id']}/cancel", ['reason' => 'Servico lancado em duplicidade no caixa'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $cancelled = FiscalDocument::findOrFail($document['id']);
        $this->assertStringStartsWith('EVT', $cancelled->cancellation_protocol);
        $this->assertStringContainsString('<evento', Storage::disk('local')->get($cancelled->cancellation_xml_path));
        $request = $this->sefin->xmlFor('event')[0];
        $this->assertTrue(Signer::isSigned($request, 'infPedReg'));
        $this->assertStringContainsString('Id="PRE'.$document['access_key'].'101101"', $request);
        $this->assertStringContainsString('<cMotivo>9</cMotivo><xMotivo>Servico lancado em duplicidade no caixa</xMotivo>', $request);
        $this->assertStringEndsWith("/nfse/{$document['access_key']}/eventos", $this->sefin->requestsFor('event')[0]->url);
    }

    public function test_a_cancellation_refused_by_the_municipality_deadline_keeps_the_document_authorized(): void
    {
        $document = $this->issueSale($this->paidServiceSale([[$this->makeTaxedService(), 1]]))->json('data.0');
        $this->sefin->script('event', 'E0822');

        $this->postJson("/api/professional/fiscal-documents/{$document['id']}/cancel", ['reason' => 'Servico lancado em duplicidade no caixa'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'O Sistema Nacional NFS-e recusou o cancelamento: E0822 - O prazo para o cancelamento da NFS-e expirou, conforme parametrização do município emissor da NFS-e.');

        $this->assertSame('authorized', FiscalDocument::findOrFail($document['id'])->status->value);
    }

    public function test_cancellation_while_the_api_is_down_answers_503(): void
    {
        $document = $this->issueSale($this->paidServiceSale([[$this->makeTaxedService(), 1]]))->json('data.0');
        $this->sefin->script('event', 'http:502');

        $this->postJson("/api/professional/fiscal-documents/{$document['id']}/cancel", ['reason' => 'Servico lancado em duplicidade no caixa'])->assertStatus(503);
    }

    public function test_xml_and_danfse_of_the_nfse_can_be_downloaded(): void
    {
        $document = $this->issueSale($this->paidServiceSale([[$this->makeTaxedService(), 1]]))->json('data.0');

        $xml = $this->actingAs($this->owner, 'sanctum')->get("/api/professional/fiscal-documents/{$document['id']}/xml")->assertOk();
        $pdf = $this->get("/api/professional/fiscal-documents/{$document['id']}/pdf")->assertOk();

        $this->assertStringContainsString('<NFSe', $xml->streamedContent());
        $this->assertStringStartsWith('%PDF', $pdf->streamedContent());
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
    }

    public function test_no_secret_reaches_the_logs_while_issuing_failing_and_retrying(): void
    {
        $logged = [];
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Log\Events\MessageLogged::class, function ($message) use (&$logged): void {
            $logged[] = $message->message.json_encode($message->context);
        });
        $this->sefin->script('emission', new RuntimeException('cURL error 35: TLS handshake failed'));

        $document = $this->issueSale($this->paidServiceSale([[$this->makeTaxedService(), 1]]))->json('data.0');
        $this->postJson("/api/professional/fiscal-documents/{$document['id']}/sync")->assertOk();

        $this->assertNotEmpty($logged);
        foreach ($logged as $line) {
            $this->assertStringNotContainsString(TestCertificate::PASSWORD, $line);
            $this->assertStringNotContainsString('PRIVATE KEY', $line);
            $this->assertStringNotContainsString('dpsXmlGZipB64', $line);
        }
    }
}
