<?php

namespace Tests\Feature\Finance;

use App\Models\ClientCreditInstallment;
use App\Models\ClientCreditPlan;
use App\Models\CompanyClientAccount;
use App\Models\FinancialEntry;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Sale;
use App\Models\SaleReceipt;
use App\Models\User;
use App\Services\Commercial\ClientAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Crediário parcelado (docs/gap-petflow/PLANO.md, 2.2), de ponta a ponta: venda de R$ 300,00
 * concluída a prazo em 2 parcelas de 150,00 (31/10 e 30/11), política padrão (multa 2%, juros 1%
 * a.m., sem carência). Em 10/11 a 1ª está 10 dias vencida: 150,00 + 3,00 + 0,50 = 153,50
 * (golden `crediario-01`).
 */
class ClientCreditTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    private int $saleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildClinic();
        Carbon::setTestNow('2026-10-01 10:00:00');
        CompanyClientAccount::create(['organization_id' => $this->clinic->id, 'client_id' => $this->tutor->id, 'allow_credit_sale' => true, 'credit_limit' => '1000.00']);
        $this->openRegisterFor($this->receptionist);
        $this->saleId = $this->actingAs($this->receptionist, 'sanctum')->postJson('/api/professional/sales', ['kind' => 'sale', 'client_id' => $this->tutor->id])->assertCreated()->json('data.id');
        $this->postJson("/api/professional/sales/{$this->saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $this->makeProduct(['price' => 300, 'controls_stock' => false])->id])->assertCreated();
        $this->postJson("/api/professional/sales/{$this->saleId}/complete", ['credit_installments' => 2, 'first_due_date' => '2026-10-31'])
            ->assertOk()->assertJsonPath('data.status', 'unpaid');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_complete_creates_the_plan_with_the_policy_snapshot(): void
    {
        $plan = ClientCreditPlan::sole();
        $this->assertSame(['300.00', 2, '2.00', '1.0000', 0], [(string) $plan->total, $plan->installments_count, (string) $plan->late_fee_percent, (string) $plan->monthly_interest_percent, $plan->grace_days]);
        $this->assertSame('300.00', $this->fiado());

        $this->installments()->assertOk()
            ->assertJsonPath('data.*.due_date', ['2026-10-31', '2026-11-30'])
            ->assertJsonPath('data.*.amount', ['150.00', '150.00'])
            ->assertJsonPath('data.*.status', ['upcoming', 'upcoming']);

        // O balcão conhece a venda pelo número, não pelo id interno.
        $number = \App\Models\Sale::find($this->saleId)->number;
        $this->installments()->assertJsonPath('data.*.sale_number', [$number, $number]);
        $this->quote('2026-11-10')->assertJsonPath('data.installments.0.sale_number', $number);
    }

    public function test_sale_resource_tells_the_front_the_sale_is_on_credit(): void
    {
        $planId = ClientCreditPlan::sole()->id;
        $expected = ['id' => $planId, 'installments_count' => 2, 'client_id' => $this->tutor->id, 'status' => 'active'];

        $this->actingAs($this->receptionist, 'sanctum')->getJson("/api/professional/sales/{$this->saleId}")->assertOk()
            ->assertJsonPath('data.has_credit_plan', true)
            ->assertJsonPath('data.credit_plan', $expected);

        // Na listagem também, sem consulta por venda (o plano vem no eager load).
        $other = $this->actingAs($this->receptionist, 'sanctum')->postJson('/api/professional/sales', ['kind' => 'sale'])->json('data.id');
        $list = collect($this->getJson('/api/professional/sales')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame([true, false, null], [$list[$this->saleId]['has_credit_plan'], $list[$other]['has_credit_plan'], $list[$other]['credit_plan']]);

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/professional/sales/{$this->saleId}/cancel", ['reason' => 'Desistiu'])->assertOk()
            ->assertJsonPath('data.has_credit_plan', false)
            ->assertJsonPath('data.credit_plan', null);
    }

    public function test_quote_computes_fees_on_reading(): void
    {
        $this->quote('2026-11-10')->assertOk()
            ->assertJsonPath('data.installments.0.days_late', 10)
            ->assertJsonPath('data.installments.0.late_fee', '3.00')
            ->assertJsonPath('data.installments.0.interest', '0.50')
            ->assertJsonPath('data.installments.0.total_due', '153.50')
            ->assertJsonPath('data.installments.1.fees', '0.00')
            ->assertJsonPath('data.totals.total_due', '303.50');
        $this->assertSame('0.00', (string) ClientCreditInstallment::sum('paid_fees'));
    }

    public function test_partial_then_full_payment_fifo_with_fees_as_financial_revenue(): void
    {
        Carbon::setTestNow('2026-11-10 10:00:00');
        $this->pay(['amount' => '100.00'])->assertCreated()
            ->assertJsonPath('data.fees_amount', '3.50')
            ->assertJsonPath('data.principal_amount', '96.50');

        // O principal é um recebimento da venda (o fiado na conta cai junto); os encargos, receita financeira.
        $sale = Sale::findOrFail($this->saleId);
        $this->assertSame(['96.50', 'unpaid'], [(string) $sale->paid_amount, $sale->status->value]);
        $this->assertSame('203.50', $this->fiado());
        $fees = FinancialEntry::where('reference_type', 'client_credit_payment')->sole();
        $this->assertSame(['3.50', 'revenue', 'financial'], [(string) $fees->net_amount, $fees->nature->value, $fees->category->dre_treatment->value]);
        $this->assertSame('3.50', collect($this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/professional/reports/income-statement?regime=managerial&from=2026-11-01&to=2026-11-30')->assertOk()
            ->json('data.lines'))->firstWhere('key', 'financial_result')['total']);
        $this->installments()->assertJsonPath('data.*.status', ['overdue', 'upcoming']);

        // Quita tudo: 53,50 da 1ª (encargo já acertado hoje, sem juros novos) + 150,00 da 2ª.
        $total = $this->quote('2026-11-10')->json('data.totals.total_due');
        $this->assertSame('203.50', $total);
        $this->pay(['amount' => $total])->assertCreated();

        $this->assertSame('paid', Sale::findOrFail($this->saleId)->status->value);
        $this->assertSame('0.00', $this->fiado());
        $this->assertSame(ClientCreditPlan::PAID, ClientCreditPlan::sole()->status);
        $this->installments()->assertJsonPath('data.*.status', ['paid', 'paid']);
    }

    public function test_fee_discount_needs_the_permission(): void
    {
        Carbon::setTestNow('2026-11-10 10:00:00');
        $this->pay(['amount' => '150.00', 'fee_discount' => '3.50'])->assertForbidden();

        $this->openRegisterFor($this->owner);
        $this->pay(['amount' => '150.00', 'fee_discount' => '3.50'], $this->owner)->assertCreated()
            ->assertJsonPath('data.fee_discount', '3.50')
            ->assertJsonPath('data.principal_amount', '150.00');
        $this->installments()->assertJsonPath('data.0.status', 'paid');
    }

    public function test_overpaying_and_other_organizations_are_refused(): void
    {
        $this->pay(['amount' => '300.01'])->assertStatus(422)->assertJsonValidationErrors('amount');

        $stranger = User::factory()->professional()->create();
        OrganizationMember::factory()->owner()->for(Organization::factory()->petshop()->create(), 'organization')->create(['user_id' => $stranger->id]);
        $this->actingAs($stranger, 'sanctum')->getJson("/api/professional/clients/{$this->tutor->id}/credit-installments")->assertNotFound();
    }

    public function test_direct_receipt_on_a_credit_sale_is_refused(): void
    {
        $this->actingAs($this->receptionist, 'sanctum')
            ->postJson("/api/professional/sales/{$this->saleId}/receipts", ['payment_method_id' => $this->paymentMethodId($this->receptionist, 'cash'), 'amount' => '50.00'])
            ->assertStatus(422);
    }

    public function test_cancelling_a_credit_receipt_puts_the_principal_back_on_the_installments(): void
    {
        $this->pay(['amount' => '150.00'])->assertCreated();
        $this->installments()->assertJsonPath('data.0.status', 'paid');
        $receiptId = SaleReceipt::where('sale_id', $this->saleId)->sole()->id;

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/professional/sales/{$this->saleId}/receipts/{$receiptId}/cancel", ['reason' => 'Cheque devolvido'])->assertOk();

        $this->installments()->assertJsonPath('data.0.paid_principal', '0.00')->assertJsonPath('data.0.status', 'upcoming');
        $this->assertSame('300.00', $this->fiado());
        $this->assertSame(ClientCreditPlan::ACTIVE, ClientCreditPlan::sole()->status);
    }

    public function test_cancelling_the_sale_cancels_the_plan(): void
    {
        $this->pay(['amount' => '100.00'])->assertCreated();

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/professional/sales/{$this->saleId}/cancel", ['reason' => 'Desistiu'])->assertOk();

        $this->assertSame(ClientCreditPlan::CANCELLED, ClientCreditPlan::sole()->status);
        $this->assertSame([ClientCreditInstallment::CANCELLED], ClientCreditInstallment::pluck('status')->unique()->values()->all());
        $this->assertSame('0.00', $this->fiado());
        $this->installments()->assertJsonCount(0, 'data');
    }

    public function test_policy_is_configured_per_organization_within_the_legal_limits(): void
    {
        $this->actingAs($this->owner, 'sanctum')->putJson('/api/professional/fiscal-settings', ['credit_late_fee_percent' => '2.5', 'credit_monthly_interest_percent' => '1.5'])
            ->assertStatus(422)->assertJsonValidationErrors(['credit_late_fee_percent', 'credit_monthly_interest_percent']);
        $this->putJson('/api/professional/fiscal-settings', ['credit_late_fee_percent' => '1.5', 'credit_monthly_interest_percent' => '0.5', 'credit_grace_days' => 5])
            ->assertOk()->assertJsonPath('data.credit_late_fee_percent', '1.50')->assertJsonPath('data.credit_grace_days', 5);

        // O plano já criado guarda a política do dia em que nasceu.
        $this->assertSame('2.00', (string) ClientCreditPlan::sole()->late_fee_percent);
    }

    private function installments(): TestResponse
    {
        return $this->actingAs($this->receptionist, 'sanctum')->getJson("/api/professional/clients/{$this->tutor->id}/credit-installments");
    }

    private function quote(string $date): TestResponse
    {
        return $this->actingAs($this->receptionist, 'sanctum')->postJson("/api/professional/clients/{$this->tutor->id}/credit-payments/quote", ['date' => $date]);
    }

    /** @param  array<string, mixed>  $body */
    private function pay(array $body, ?User $as = null): TestResponse
    {
        $as ??= $this->receptionist;

        return $this->actingAs($as, 'sanctum')->postJson("/api/professional/clients/{$this->tutor->id}/credit-payments", $body + ['payment_method_id' => $this->paymentMethodId($as, 'cash')]);
    }

    private function fiado(): string
    {
        return app(ClientAccountService::class)->saleDebitBalance($this->saleId)->toString();
    }
}
