<?php

namespace Tests\Feature\Fiscal;

use App\DataTransferObjects\Fiscal\FiscalProviderResult;
use App\Enums\FiscalDocumentStatus;
use App\Models\FiscalDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Fiscal\Concerns\BuildsFiscalScenarios;
use Tests\Support\ScriptedFiscalProviderGateway;
use Tests\TestCase;

/**
 * Contingência da NFC-e, indisponibilidade do provedor e reconciliação — spec
 * docs/gap-petflow/specs/fase4-fiscal-sem-envio.md §4 (máquina de estados).
 */
class FiscalContingencyTest extends TestCase
{
    use BuildsFiscalScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildClinic();
        Carbon::setTestNow('2026-11-08 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_nfce_issued_in_contingency_counts_for_the_sale_and_is_authorized_on_leaving(): void
    {
        $this->enterContingency();
        $saleId = $this->paidProductSale([[$this->makeProduct(), 1]]);

        $document = $this->issueSale($saleId)->assertCreated()->json('data.0');
        $this->assertSame(['contingency', 'offline_contingency', 'SEFAZ SP fora do ar desde as 9h'], [$document['status'], $document['emission_mode'], $document['contingency_reason']]);
        $this->assertSame('9', substr($document['access_key'], 34, 1));

        $this->getJson("/api/professional/sales/{$saleId}")->assertJsonPath('data.fiscal_pending', []);
        $this->getJson('/api/professional/reports/fiscal-pending')->assertJsonPath('data.0.id', $document['id']);
        $this->postJson("/api/professional/fiscal-documents/{$document['id']}/cancel", ['reason' => 'Erro'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Documento em contingência: transmita antes de cancelar.');

        $this->deleteJson('/api/professional/fiscal-contingency')
            ->assertOk()
            ->assertJsonPath('data.active', false)
            ->assertJsonPath('transmission.authorized', 1);

        $this->assertSame(FiscalDocumentStatus::AUTHORIZED, FiscalDocument::findOrFail($document['id'])->status);
        $this->getJson('/api/professional/reports/fiscal-pending')->assertJsonCount(0, 'data');
    }

    public function test_nfe_waits_while_in_contingency_and_goes_out_when_it_ends(): void
    {
        $gateway = ScriptedFiscalProviderGateway::bind($this->app);
        $this->enterContingency();

        $document = $this->issueSale($this->paidProductSale([[$this->makeProduct(), 1]], ['operation' => 'in_person_resale']))->assertCreated()->json('data.0');

        $this->assertSame(['nfe', 'pending', 'normal'], [$document['kind'], $document['status'], $document['emission_mode']]);
        $this->assertStringContainsString('contingência', $document['last_error']);
        $this->assertSame(0, $gateway->calls['issue'] ?? 0);

        $this->deleteJson('/api/professional/fiscal-contingency')->assertOk()->assertJsonPath('transmission.authorized', 1);
        $this->assertSame(1, $gateway->calls['issue']);
    }

    public function test_provider_outage_keeps_the_document_pending_with_backoff_and_reconcile_retries_it(): void
    {
        $gateway = ScriptedFiscalProviderGateway::bind($this->app)->script('issue', ScriptedFiscalProviderGateway::unavailable());

        $document = $this->issueSale($this->paidProductSale([[$this->makeProduct(), 1]]))->assertCreated()->json('data.0');
        $this->assertSame(['pending', 1, 'SEFAZ fora do ar (timeout)', 'scripted'], [$document['status'], $document['attempts'], $document['last_error'], $document['provider']]);
        $this->assertSame('2026-11-08T10:01:00+00:00', Carbon::parse($document['next_retry_at'])->utc()->toIso8601String());

        $this->artisan('fiscal:reconcile')->assertSuccessful();
        $this->assertSame(FiscalDocumentStatus::PENDING, FiscalDocument::findOrFail($document['id'])->status, 'Antes do horário da nova tentativa nada é reenviado.');

        Carbon::setTestNow('2026-11-08 10:02:00');
        $this->artisan('fiscal:reconcile')->assertSuccessful();

        $reconciled = FiscalDocument::findOrFail($document['id']);
        $this->assertSame([FiscalDocumentStatus::AUTHORIZED, 1, null], [$reconciled->status, $reconciled->number, $reconciled->next_retry_at]);
        $gateway->assertScriptsConsumed();
    }

    public function test_sync_resolves_a_processing_document_through_check_status(): void
    {
        ScriptedFiscalProviderGateway::bind($this->app)
            ->script('issue', new FiscalProviderResult(FiscalDocumentStatus::PROCESSING, providerId: 'lote-1'))
            ->script('checkStatus', new FiscalProviderResult(FiscalDocumentStatus::AUTHORIZED, accessKey: str_repeat('3', 44), protocol: '135260000000001'));

        $id = $this->issueSale($this->paidProductSale([[$this->makeProduct(), 1]]))->assertCreated()->assertJsonPath('data.0.status', 'processing')->json('data.0.id');

        $this->postJson("/api/professional/fiscal-documents/{$id}/sync")
            ->assertOk()
            ->assertJsonPath('data.status', 'authorized')
            ->assertJsonPath('data.protocol', '135260000000001')
            ->assertJsonPath('data.access_key', str_repeat('3', 44));
    }

    public function test_contingency_documents_older_than_the_deadline_are_flagged_overdue(): void
    {
        $this->enterContingency();
        $id = $this->issueSale($this->paidProductSale([[$this->makeProduct(), 1]]))->assertCreated()->json('data.0.id');

        $this->getJson('/api/professional/reports/fiscal-pending')->assertJsonPath('data.0.is_contingency_overdue', false);

        Carbon::setTestNow('2026-11-09 10:01:00');
        $this->getJson('/api/professional/reports/fiscal-pending')->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.is_contingency_overdue', true);
        $this->getJson('/api/professional/fiscal-contingency')->assertJsonPath('data.overdue_documents', 1)->assertJsonPath('data.max_hours', 24);
    }

    public function test_contingency_entry_rules_and_permissions(): void
    {
        $this->actingAs($this->receptionist, 'sanctum')->postJson('/api/professional/fiscal-contingency', ['reason' => 'SEFAZ fora do ar agora'])->assertForbidden();
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/fiscal-contingency', ['reason' => 'curto'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->deleteJson('/api/professional/fiscal-contingency')->assertStatus(422);

        $this->enterContingency();
        $this->postJson('/api/professional/fiscal-contingency', ['reason' => 'SEFAZ fora do ar de novo'])->assertStatus(422);
        $this->getJson('/api/professional/fiscal-settings')->assertJsonPath('data.contingency.active', true)->assertJsonPath('data.provider.name', 'log');
    }

    private function enterContingency(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/professional/fiscal-contingency', ['reason' => 'SEFAZ SP fora do ar desde as 9h'])
            ->assertOk()
            ->assertJsonPath('data.active', true);
    }
}
