<?php

namespace Tests\Feature\Fiscal;

use App\Models\FiscalDocument;
use App\Models\FiscalNumberInvalidation;
use App\Models\Product;
use App\Services\Fiscal\Sefaz\SefazCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Fiscal\Concerns\BuildsSefazScenario;
use Tests\TestCase;

/**
 * `SefazFiscalGateway` de ponta a ponta pelos endpoints — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfe-nfce.md §7. A SEFAZ é o dublê `FakeSefazTransport` (nunca a real);
 * o XML é montado, assinado com o A1 de teste e validado no XSD oficial de verdade.
 */
class SefazFiscalGatewayTest extends TestCase
{
    use BuildsSefazScenario;
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSefazClinic();
        $this->product = $this->makeProduct();
    }

    public function test_nfce_is_authorized_synchronously_and_the_procnfe_and_danfce_are_stored(): void
    {
        $document = $this->issueSale($this->paidProductSale([[$this->product, 2]]))->assertCreated()->json('data.0');

        $this->assertSame(['nfce', 'authorized', 'sefaz', true, true], [$document['kind'], $document['status'], $document['provider'], $document['has_xml'], $document['has_pdf']]);
        $this->assertMatchesRegularExpression('/^\d{15}$/', $document['protocol']);
        $stored = FiscalDocument::findOrFail($document['id']);
        $procNfe = Storage::disk('local')->get($stored->xml_path);
        $this->assertStringEndsWith('procNFe.xml', $stored->xml_path);
        $this->assertStringContainsString('<nfeProc', $procNfe);
        $this->assertStringContainsString('<nProt>'.$document['protocol'].'</nProt>', $procNfe);
        $this->assertStringContainsString('<xProd>'.SefazCodes::HOMOLOGATION_NFCE_FIRST_ITEM.'</xProd>', $procNfe);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($stored->pdf_path));
        $this->assertStringContainsString('<indSinc>1</indSinc>', $this->sefaz->requestsFor('authorization')[0]);
        $this->assertSame($stored->access_key, substr($stored->xml_path, -56, 44));
    }

    public function test_nfe_to_an_identified_customer_carries_the_homologation_recipient_name(): void
    {
        $this->identifyTutor();
        $saleId = $this->paidProductSale([[$this->product, 1]], ['operation' => 'in_person_resale', 'client_id' => $this->tutor->id]);

        $document = $this->issueSale($saleId)->assertCreated()->json('data.0');

        $this->assertSame(['nfe', 'authorized'], [$document['kind'], $document['status']]);
        $sent = $this->sefaz->requestsFor('authorization')[0];
        $this->assertStringContainsString('<mod>55</mod>', $sent);
        $this->assertStringContainsString('<xNome>'.SefazCodes::HOMOLOGATION_RECIPIENT.'</xNome>', $sent);
        $this->assertStringContainsString('<CPF>52998224725</CPF>', $sent);
    }

    public function test_denied_and_rejected_answers_map_to_their_statuses_with_the_sefaz_reason(): void
    {
        $this->sefaz->script('authorization', '302', '225');

        $denied = $this->issueSale($this->paidProductSale([[$this->product, 1]]))->assertCreated()->json('data.0');
        $rejected = $this->issueSale($this->paidProductSale([[$this->product, 1]]))->assertCreated()->json('data.0');

        $this->assertSame('denied', $denied['status']);
        $this->assertStringStartsWith('302 - Uso Denegado', $denied['rejection_reason']);
        $this->assertTrue($denied['has_xml'], 'NF-e denegada também é guardada (procNFe com o protocolo de denegação).');
        $this->assertSame('rejected', $rejected['status']);
        $this->assertSame('225 - Rejeicao: Falha no Schema XML da NFe', $rejected['rejection_reason']);
        $this->assertFalse($rejected['has_pdf']);
    }

    public function test_an_asynchronous_answer_stays_processing_until_the_receipt_is_consulted(): void
    {
        $this->sefaz->script('authorization', '103');

        $document = $this->issueSale($this->paidProductSale([[$this->product, 1]]))->assertCreated()->json('data.0');
        $this->assertSame('processing', $document['status']);
        $this->assertSame('351000000000777', FiscalDocument::find($document['id'])->provider_id);

        $this->postJson("/api/professional/fiscal-documents/{$document['id']}/sync")->assertOk()->assertJsonPath('data.status', 'authorized');
        $this->assertCount(1, $this->sefaz->requestsFor('receipt'));
    }

    public function test_a_transport_failure_keeps_the_document_pending_and_the_retry_resends_the_same_signed_xml(): void
    {
        $this->sefaz->script('authorization', \Tests\Support\Fiscal\FakeSefazTransport::timeoutFault(), '204');

        $document = $this->issueSale($this->paidProductSale([[$this->product, 1]]))->assertCreated()->json('data.0');

        $this->assertSame(['pending', 1], [$document['status'], $document['attempts']]);
        $this->assertStringContainsString('SEFAZ', $document['last_error']);
        $this->assertNotNull($document['next_retry_at']);
        $pending = FiscalDocument::find($document['id']);
        $this->assertNotNull($pending->access_key, 'A chave e o XML assinado são gravados antes do envio (write-ahead).');

        // A resposta se perdeu mas a SEFAZ autorizou: o reenvio do MESMO XML volta 204 e a consulta recupera o protocolo.
        $this->postJson("/api/professional/fiscal-documents/{$document['id']}/sync")->assertOk()->assertJsonPath('data.status', 'authorized')->assertJsonPath('data.access_key', $pending->access_key);
        [$first, $second] = $this->sefaz->requestsFor('authorization');
        $this->assertSame($this->keyOf($first), $this->keyOf($second));
        $this->assertCount(1, $this->sefaz->requestsFor('query'));
    }

    public function test_sefaz_out_of_service_108_is_a_transport_failure_not_a_rejection(): void
    {
        $this->sefaz->script('authorization', '108');

        $document = $this->issueSale($this->paidProductSale([[$this->product, 1]]))->assertCreated()->json('data.0');

        $this->assertSame('pending', $document['status']);
        $this->assertStringContainsString('108', $document['last_error']);
        $this->assertNull($document['rejection_reason']);
    }

    public function test_cancelling_registers_the_110111_event_and_stores_the_event_xml(): void
    {
        $document = $this->issueSale($this->paidProductSale([[$this->product, 1]]))->json('data.0');

        $this->postJson("/api/professional/fiscal-documents/{$document['id']}/cancel", ['reason' => 'Cliente desistiu da compra no caixa'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $cancelled = FiscalDocument::find($document['id']);
        $this->assertMatchesRegularExpression('/^\d{15}$/', $cancelled->cancellation_protocol);
        $this->assertStringContainsString('<procEventoNFe', Storage::disk('local')->get($cancelled->cancellation_xml_path));
        $event = $this->sefaz->requestsFor('event')[0];
        $this->assertStringContainsString('<tpEvento>110111</tpEvento>', $event);
        $this->assertStringContainsString('<nProt>'.$document['protocol'].'</nProt>', $event);
    }

    public function test_a_cancellation_refused_by_sefaz_keeps_the_document_authorized(): void
    {
        $document = $this->issueSale($this->paidProductSale([[$this->product, 1]]))->json('data.0');
        $this->sefaz->script('event', '501');

        $this->postJson("/api/professional/fiscal-documents/{$document['id']}/cancel", ['reason' => 'Cliente desistiu da compra no caixa'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'A SEFAZ recusou o cancelamento: 501 - Rejeicao: Prazo de cancelamento superior ao previsto na Legislacao');

        $this->assertSame('authorized', FiscalDocument::find($document['id'])->status->value);
    }

    public function test_invalidating_a_gap_is_homologated_and_the_procinutnfe_is_stored(): void
    {
        $this->actingAs($this->owner, 'sanctum')->putJson('/api/professional/fiscal-settings', ['fiscal_series' => ['nfce' => ['next_number' => 5]]])->assertOk();
        \App\Models\FiscalNumberSequence::query()->where('organization_id', $this->clinic->id)->where('kind', 'nfce')->update(['start_number' => 1]);

        $this->postJson('/api/professional/fiscal-number-invalidations', ['kind' => 'nfce', 'number_from' => 1, 'number_to' => 4, 'reason' => 'Numeracao pulada na migracao do sistema'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'authorized');

        $invalidation = FiscalNumberInvalidation::query()->firstOrFail();
        $this->assertSame('sefaz', $invalidation->provider);
        $this->assertStringContainsString('<ProcInutNFe', Storage::disk('local')->get($invalidation->xml_path));
        $request = $this->sefaz->requestsFor('invalidation')[0];
        $this->assertStringContainsString('<nNFIni>1</nNFIni><nNFFin>4</nNFFin>', $request);
        $this->assertStringContainsString('<mod>65</mod>', $request);
    }

    public function test_offline_contingency_signs_locally_with_qr_code_and_transmits_when_the_clinic_leaves_it(): void
    {
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/fiscal-contingency', ['reason' => 'SEFAZ SP fora do ar desde as 9h'])->assertOk();

        $document = $this->issueSale($this->paidProductSale([[$this->product, 1]]))->assertCreated()->json('data.0');

        $this->assertSame(['contingency', 'offline_contingency', true], [$document['status'], $document['emission_mode'], $document['has_pdf']]);
        $this->assertSame('9', $document['access_key'][34]);
        $this->assertSame([], $this->sefaz->requestsFor('authorization'), 'Em contingência nada vai à SEFAZ na emissão.');
        $signed = Storage::disk('local')->get(FiscalDocument::find($document['id'])->xml_path);
        $this->assertStringContainsString('<tpEmis>9</tpEmis>', $signed);
        $this->assertStringContainsString('<qrCode>', $signed);
        $this->assertStringContainsString('<xJust>SEFAZ SP fora do ar desde as 9h</xJust>', $signed);

        $this->actingAs($this->owner, 'sanctum')->deleteJson('/api/professional/fiscal-contingency')->assertOk()->assertJsonPath('transmission.authorized', 1);

        $transmitted = FiscalDocument::find($document['id']);
        $this->assertSame(['authorized', $document['access_key']], [$transmitted->status->value, $transmitted->access_key]);
        $this->assertSame($document['access_key'], $this->keyOf($this->sefaz->requestsFor('authorization')[0]));
        $this->assertStringEndsWith('procNFe.xml', $transmitted->xml_path);
    }

    public function test_without_a_certificate_the_organization_keeps_the_simulated_emission(): void
    {
        $this->actingAs($this->owner, 'sanctum')->deleteJson('/api/professional/fiscal-settings/certificate')->assertOk();

        $document = $this->issueSale($this->paidProductSale([[$this->product, 1]]))->assertCreated()->json('data.0');

        $this->assertSame(['authorized', 'log', false], [$document['status'], $document['provider'], $document['has_xml']]);
        $this->assertSame([], $this->sefaz->requests);
    }

    public function test_no_secret_reaches_the_logs_while_issuing_failing_and_retrying(): void
    {
        $logged = [];
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Log\Events\MessageLogged::class, function ($message) use (&$logged): void {
            $logged[] = $message->message.json_encode($message->context);
        });
        $this->sefaz->script('authorization', \Tests\Support\Fiscal\FakeSefazTransport::timeoutFault());

        $document = $this->issueSale($this->paidProductSale([[$this->product, 1]]))->json('data.0');
        $this->postJson("/api/professional/fiscal-documents/{$document['id']}/sync")->assertOk();

        $this->assertNotEmpty($logged, 'A falha de transporte é logada (fiscal.provider_unavailable).');
        foreach ($logged as $line) {
            $this->assertStringNotContainsString(self::CSC_TOKEN, $line);
            $this->assertStringNotContainsString(\Tests\Support\Fiscal\TestCertificate::PASSWORD, $line);
            $this->assertStringNotContainsString('PRIVATE KEY', $line);
        }
    }

    private function keyOf(string $request): string
    {
        preg_match('/Id="NFe(\d{44})"/', $request, $match);

        return $match[1];
    }
}
