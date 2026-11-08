<?php

namespace Tests\Feature\Fiscal;

use App\DataTransferObjects\Fiscal\FiscalProviderCapabilities;
use App\DataTransferObjects\Fiscal\FiscalProviderResult;
use App\Enums\FiscalDocumentStatus;
use App\Models\FiscalNumberInvalidation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Fiscal\Concerns\BuildsFiscalScenarios;
use Tests\Support\ScriptedFiscalProviderGateway;
use Tests\TestCase;

/**
 * Lacunas e inutilização de numeração — spec docs/gap-petflow/specs/fase4-fiscal-sem-envio.md
 * §3.7 (golden `fiscal-inut-01` pelo caminho real).
 */
class FiscalNumberInvalidationTest extends TestCase
{
    use BuildsFiscalScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildClinic();
    }

    public function test_a_rejected_number_becomes_a_gap_that_can_be_invalidated(): void
    {
        $gateway = ScriptedFiscalProviderGateway::bind($this->app)
            ->script('issue', new FiscalProviderResult(FiscalDocumentStatus::REJECTED, rejectionReason: '539 - Duplicidade de NF-e'));
        $saleId = $this->paidProductSale([[$this->makeProduct(), 1]]);

        $this->issueSale($saleId)->assertCreated()->assertJsonPath('data.0.status', 'rejected')->assertJsonPath('data.0.number', 1);
        // Rejeitado não ocupa a venda: a reemissão gera documento novo, com o próximo número.
        $this->issueSale($saleId)->assertCreated()->assertJsonPath('data.0.status', 'authorized')->assertJsonPath('data.0.number', 2);

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/professional/fiscal-number-invalidations/gaps?kind=nfce')
            ->assertOk()
            ->assertJsonPath('data.next_number', 3)
            ->assertJsonPath('data.gaps', [['from' => 1, 'to' => 1]]);

        $this->postJson('/api/professional/fiscal-number-invalidations', ['kind' => 'nfce', 'number_from' => 1, 'number_to' => 1, 'reason' => 'Nota rejeitada pela SEFAZ e reemitida'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'authorized')
            ->assertJsonPath('data.series', '1')
            ->assertJsonPath('data.environment', 'homologation')
            ->assertJsonPath('data.protocol', 'I-1')
            ->assertJsonPath('data.user.id', $this->owner->id);

        $this->getJson('/api/professional/fiscal-number-invalidations/gaps?kind=nfce')->assertJsonPath('data.gaps', []);
        $this->getJson('/api/professional/fiscal-number-invalidations')->assertOk()->assertJsonCount(1, 'data');
        $gateway->assertScriptsConsumed();
    }

    public function test_range_rules_are_enforced(): void
    {
        $this->issueSale($this->paidProductSale([[$this->makeProduct(), 1]]))->assertCreated();
        FiscalNumberInvalidation::create([
            'organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id, 'kind' => 'nfce', 'series' => '1',
            'environment' => 'homologation', 'number_from' => 2, 'number_to' => 2, 'reason' => 'Pedido anterior pendente', 'status' => 'pending',
        ]);
        $this->actingAs($this->owner, 'sanctum');
        $reason = 'Número pulado por falha de conexão';

        $this->postJson('/api/professional/fiscal-number-invalidations', ['kind' => 'nfce', 'number_from' => 1, 'number_to' => 1, 'reason' => $reason])->assertStatus(422)->assertJsonValidationErrors('number_from');
        $this->postJson('/api/professional/fiscal-number-invalidations', ['kind' => 'nfce', 'number_from' => 2, 'number_to' => 2, 'reason' => $reason])->assertStatus(422)->assertJsonPath('errors.number_from.0', 'A faixa se sobrepõe a uma inutilização pendente ou homologada.');
        $this->postJson('/api/professional/fiscal-number-invalidations', ['kind' => 'nfce', 'number_from' => 3, 'number_to' => 9, 'reason' => $reason])->assertStatus(422)->assertJsonValidationErrors('number_to');
        $this->postJson('/api/professional/fiscal-number-invalidations', ['kind' => 'nfse', 'number_from' => 3, 'number_to' => 3, 'reason' => $reason])->assertStatus(422)->assertJsonValidationErrors('kind');
        $this->postJson('/api/professional/fiscal-number-invalidations', ['kind' => 'nfce', 'number_from' => 3, 'number_to' => 3, 'reason' => 'curta'])->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_a_driver_without_invalidation_capability_is_refused_up_front(): void
    {
        ScriptedFiscalProviderGateway::bind($this->app, new FiscalProviderCapabilities(invalidation: false, nfceOfflineContingency: true, returnInvoices: true));
        \App\Models\FiscalNumberSequence::create(['organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id, 'kind' => 'nfce', 'series' => '1', 'environment' => 'homologation', 'start_number' => 1, 'next_number' => 5]);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/professional/fiscal-number-invalidations', ['kind' => 'nfce', 'number_from' => 1, 'number_to' => 2, 'reason' => 'Números pulados na migração'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'O emissor fiscal configurado não faz inutilização de numeração.');
    }

    public function test_only_the_owner_invalidates(): void
    {
        $this->actingAs($this->receptionist, 'sanctum')
            ->postJson('/api/professional/fiscal-number-invalidations', ['kind' => 'nfce', 'number_from' => 1, 'number_to' => 1, 'reason' => 'Número pulado por falha'])
            ->assertForbidden();
        $this->getJson('/api/professional/fiscal-number-invalidations/gaps?kind=nfce')->assertForbidden();
    }

    public function test_settings_continue_the_numbering_from_another_system_without_counting_old_gaps(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->putJson('/api/professional/fiscal-settings', ['fiscal_series' => ['nfce' => ['series' => '2', 'next_number' => 150]]])
            ->assertOk()
            ->assertJsonPath('data.fiscal_series.nfce', ['series' => '2', 'next_number' => 150, 'start_number' => 150])
            ->assertJsonPath('data.fiscal_environment', 'homologation');

        $this->issueSale($this->paidProductSale([[$this->makeProduct(), 1]]))->assertCreated()->assertJsonPath('data.0.series', '2')->assertJsonPath('data.0.number', 150);

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/professional/fiscal-number-invalidations/gaps?kind=nfce')->assertJsonPath('data.gaps', []);
        $this->putJson('/api/professional/fiscal-settings', ['fiscal_series' => ['nfce' => ['next_number' => 150]]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'O próximo número da NFC-e precisa ser maior que 150, o último já usado na série 2.');
    }
}
