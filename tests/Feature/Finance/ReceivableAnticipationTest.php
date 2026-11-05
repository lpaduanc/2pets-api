<?php

namespace Tests\Feature\Finance;

use App\Models\FinancialAccount;
use App\Models\FinancialEntry;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\PaymentMethod;
use App\Models\Receivable;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Antecipação de recebíveis (docs/gap-petflow/PLANO.md, 2.1), de ponta a ponta. Cenário do golden
 * `antecip-01`: venda de R$ 289,53 no crédito 3x sem taxa (3 parcelas líquidas de 96,51), com as
 * datas de depósito postas em D+30/60/90 de 05/11/2026; 1,99% a.m. → taxas 1,92 / 3,84 / 5,76,
 * recebe 278,01, custo efetivo 3,98%.
 */
class ReceivableAnticipationTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    private const DATES = ['2026-12-05', '2027-01-04', '2027-02-03'];

    private int $saleId;

    /** @var list<int> */
    private array $receivableIds;

    private FinancialAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildClinic();
        Carbon::setTestNow('2026-11-05 10:00:00');
        $this->bank = FinancialAccount::create(['organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id, 'name' => 'Banco', 'type' => 'checking', 'opening_balance' => 0]);
        [$this->saleId, $this->receivableIds] = $this->cardSale();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_simulate_matches_the_golden_and_persists_nothing(): void
    {
        $this->simulate()->assertOk()
            ->assertJsonPath('data.gross_total', '289.53')
            ->assertJsonPath('data.fee_total', '11.52')
            ->assertJsonPath('data.net_total', '278.01')
            ->assertJsonPath('data.effective_cost_percent', '3.98')
            ->assertJsonPath('data.items.*.fee_amount', ['1.92', '3.84', '5.76'])
            ->assertJsonPath('data.items.*.days', [30, 60, 90]);

        $this->assertSame(['pending'], Receivable::whereIn('id', $this->receivableIds)->pluck('status')->map->value->unique()->values()->all());
        $this->assertSame(0, \App\Models\ReceivableAnticipation::count());
    }

    public function test_confirm_marks_anticipated_moves_dates_and_books_the_financial_expense(): void
    {
        $id = $this->confirm()->assertCreated()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.net_total', '278.01')
            ->assertJsonPath('data.items.0.original_expected_date', '2026-12-05')
            ->json('data.id');

        $receivables = Receivable::whereIn('id', $this->receivableIds)->get();
        $this->assertTrue($receivables->every(fn (Receivable $receivable): bool => $receivable->status->value === 'anticipated'
            && $receivable->expected_date->toDateString() === '2026-11-05' && $receivable->anticipation_id === $id));

        $entry = FinancialEntry::where('reference_type', 'receivable_anticipation')->where('reference_id', $id)->sole();
        $this->assertSame(['11.52', 'paid', 'expense', 'financial', $this->bank->id], [(string) $entry->net_amount, $entry->status->value, $entry->nature->value, $entry->category->dre_treatment->value, $entry->account_id]);

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/professional/receivables?status=anticipated')->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.anticipation_id', $id)
            ->assertJsonPath('data.0.anticipation.original_expected_date', '2026-12-05')
            ->assertJsonPath('data.0.anticipation.fee_amount', '1.92');
    }

    public function test_an_already_anticipated_receivable_cannot_be_anticipated_again(): void
    {
        $this->confirm()->assertCreated();

        $this->confirm()->assertStatus(422)->assertJsonValidationErrors('receivable_ids');
        $this->simulate()->assertStatus(422);
    }

    public function test_receipt_cancellation_stays_blocked_while_anticipated(): void
    {
        $this->confirm()->assertCreated();
        $receiptId = Receivable::findOrFail($this->receivableIds[0])->sale_receipt_id;

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/professional/sales/{$this->saleId}/receipts/{$receiptId}/cancel", ['reason' => 'Desistiu'])
            ->assertStatus(422);
    }

    public function test_managerial_income_statement_shows_the_fee_as_financial_result_until_cancelled(): void
    {
        $id = $this->confirm()->assertCreated()->json('data.id');
        $this->assertSame('-11.52', $this->financialResult());

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/professional/receivables/anticipations/{$id}/cancel", ['reason' => 'Desisti'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('0.00', $this->financialResult());
    }

    public function test_cancel_restores_pending_and_original_dates_but_not_after_reconciliation(): void
    {
        $id = $this->confirm()->assertCreated()->json('data.id');
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/professional/receivables/anticipations/{$id}/cancel")->assertOk();

        $receivables = Receivable::whereIn('id', $this->receivableIds)->orderBy('id')->get();
        $this->assertSame(self::DATES, $receivables->map(fn (Receivable $receivable): string => $receivable->expected_date->toDateString())->all());
        $this->assertTrue($receivables->every(fn (Receivable $receivable): bool => $receivable->status->value === 'pending' && $receivable->anticipation_id === null));
        $this->assertSame('cancelled', FinancialEntry::where('reference_type', 'receivable_anticipation')->sole()->status->value);
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/professional/receivables/anticipations/{$id}/cancel")->assertStatus(422);

        // Antecipada de novo e conciliada com depósito: não cancela mais.
        $second = $this->confirm()->assertCreated()->json('data.id');
        Receivable::whereKey($this->receivableIds[0])->update(['status' => 'settled']);
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/professional/receivables/anticipations/{$second}/cancel")->assertStatus(422);
    }

    public function test_listing_shows_the_anticipations_of_the_clinic(): void
    {
        $this->confirm()->assertCreated();

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/professional/receivables/anticipations')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.fee_total', '11.52')
            ->assertJsonPath('data.0.account_name', 'Banco')
            ->assertJsonCount(3, 'data.0.items');
    }

    public function test_permission_other_organization_and_validation(): void
    {
        $this->simulate(as: $this->receptionist)->assertForbidden();

        // Dona de outra clínica: as parcelas daqui não existem para ela.
        $stranger = User::factory()->professional()->create();
        OrganizationMember::factory()->owner()->for(Organization::factory()->petshop()->create(), 'organization')->create(['user_id' => $stranger->id]);
        $this->simulate(as: $stranger)->assertStatus(422)->assertJsonValidationErrors('receivable_ids');

        $this->simulate(['anticipated_at' => '2026-11-04'])->assertStatus(422)->assertJsonValidationErrors('anticipated_at');
        $this->simulate(['anticipated_at' => '2026-12-10'])->assertStatus(422)->assertJsonValidationErrors('anticipated_at');
        $this->simulate(['rate' => 0])->assertStatus(422)->assertJsonValidationErrors('rate');
        $this->simulate(['rate' => 31])->assertStatus(422)->assertJsonValidationErrors('rate');
        $this->simulate(['mode' => 'daily'])->assertStatus(422)->assertJsonValidationErrors('mode');
    }

    /** @param  array<string, mixed>  $overrides */
    private function simulate(array $overrides = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->owner, 'sanctum')->postJson('/api/professional/receivables/anticipations/simulate', $overrides + [
            'receivable_ids' => $this->receivableIds, 'anticipated_at' => '2026-11-05', 'mode' => 'monthly_rate', 'rate' => '1.99',
        ]);
    }

    private function confirm(): TestResponse
    {
        return $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/receivables/anticipations', [
            'receivable_ids' => $this->receivableIds, 'anticipated_at' => '2026-11-05', 'mode' => 'monthly_rate', 'rate' => '1.99', 'account_id' => $this->bank->id,
        ]);
    }

    private function financialResult(): string
    {
        return collect($this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/professional/reports/income-statement?regime=managerial&from=2026-11-01&to=2026-11-30')
            ->assertOk()->json('data.lines'))->firstWhere('key', 'financial_result')['total'];
    }

    /** @return array{int, list<int>} venda e as 3 parcelas, nas datas do golden */
    private function cardSale(): array
    {
        $credit = PaymentMethod::findOrFail($this->paymentMethodId($this->owner, 'credit_card'));
        $credit->update(['max_installments' => 12, 'fee_percent' => '0', 'fee_fixed' => '0']);
        $this->openRegisterFor($this->owner);
        $saleId = $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/sales', ['kind' => 'sale', 'client_id' => $this->tutor->id])->assertCreated()->json('data.id');
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $this->makeProduct(['price' => '289.53', 'controls_stock' => false])->id])->assertCreated();
        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $credit->id, 'amount' => '289.53', 'installments' => 3])->assertCreated();

        $receivables = Receivable::whereIn('sale_receipt_id', Sale::findOrFail($saleId)->receipts()->select('id'))->orderBy('installment_number')->get();
        foreach ($receivables as $index => $receivable) {
            $receivable->update(['expected_date' => self::DATES[$index]]);
        }
        $this->assertSame(['96.51', '96.51', '96.51'], $receivables->map(fn (Receivable $receivable): string => (string) $receivable->net_amount)->all());

        return [$saleId, $receivables->pluck('id')->all()];
    }
}
