<?php

namespace Tests\Feature\Commercial;

use App\Models\Appointment;
use App\Models\CommissionClawback;
use App\Models\CommissionRule;
use App\Models\CommissionRuleLog;
use App\Models\CommissionSettlement;
use App\Models\CommissionSettlementItem;
use App\Models\CompanyClientAccount;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Commercial\Concerns\BuildsCommissionFixtures;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Gatilho da regra de comissão — docs/gap-petflow/PLANO.md, 2.6: on_sale · on_receipt ·
 * on_execution, a conversão do antigo `only_when_received`, o estorno quando o agendamento deixa
 * de estar concluído (inclusive já fechado → desconto no próximo fechamento) e o piso comum de
 * "venda paga com recebimento" (regra de negócio 1 do doc 09).
 */
class CommissionTriggerTest extends TestCase
{
    use BuildsCommissionFixtures;
    use BuildsCounterFixtures;
    use RefreshDatabase;

    private Service $bath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildClinic();
        $this->bath = $this->makeService(['name' => 'Banho', 'price' => 50], by: $this->owner);
    }

    public function test_rules_crud_accepts_and_returns_trigger_and_keeps_only_when_received_compatible(): void
    {
        $this->actingAs($this->owner, 'sanctum');
        $base = ['scope' => 'all', 'percent' => 10];

        $created = $this->postJson('/api/professional/commission-rules', $base + ['trigger' => 'on_execution'])->assertCreated()
            ->assertJsonPath('data.trigger', 'on_execution')
            ->assertJsonPath('data.trigger_label', 'Na execução do serviço')
            ->assertJsonPath('data.only_when_received', false);
        $this->postJson('/api/professional/commission-rules', $base)->assertCreated()
            ->assertJsonPath('data.trigger', 'on_receipt')->assertJsonPath('data.only_when_received', true);
        $this->postJson('/api/professional/commission-rules', $base + ['only_when_received' => false])->assertCreated()
            ->assertJsonPath('data.trigger', 'on_sale');
        $this->postJson('/api/professional/commission-rules', $base + ['only_when_received' => true])->assertCreated()
            ->assertJsonPath('data.trigger', 'on_receipt');
        // Os dois juntos: vale `trigger`.
        $this->postJson('/api/professional/commission-rules', $base + ['only_when_received' => true, 'trigger' => 'on_sale'])->assertCreated()
            ->assertJsonPath('data.trigger', 'on_sale');
        $this->postJson('/api/professional/commission-rules', $base + ['trigger' => 'on_delivery'])->assertUnprocessable()
            ->assertJsonValidationErrors('trigger');

        $id = $created->json('data.id');
        $this->putJson("/api/professional/commission-rules/{$id}", ['trigger' => 'on_receipt', 'calculation_base' => 'margin'])->assertOk()
            ->assertJsonPath('data.trigger', 'on_receipt');
        $this->putJson("/api/professional/commission-rules/{$id}", ['only_when_received' => false])->assertOk()
            ->assertJsonPath('data.trigger', 'on_sale');

        $this->assertSame(
            [['calculation_base', 'gross', 'margin'], ['trigger', 'on_execution', 'on_receipt'], ['trigger', 'on_receipt', 'on_sale']],
            CommissionRuleLog::where('commission_rule_id', $id)->orderBy('id')->get()
                ->map(fn (CommissionRuleLog $log): array => [$log->field_changed, $log->old_value, $log->new_value])->all(),
        );
    }

    public function test_on_sale_uses_the_sale_date_and_on_receipt_the_receipt_date(): void
    {
        $saleDay = CarbonImmutable::parse('2026-10-28 10:00');
        $payDay = CarbonImmutable::parse('2026-11-03 10:00');
        $receiptRule = $this->makeCommissionRule(['percent' => 10, 'trigger' => 'on_receipt']);
        $this->makeCommissionRule(['percent' => 10, 'trigger' => 'on_sale', 'staff_id' => $this->groomerMember->id]);

        $this->travelTo($saleDay);
        $saleId = $this->serviceSale();
        $this->travelTo($payDay);
        $this->pay($saleId);

        $this->open('2026-10-31')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.trigger', 'on_sale')
            ->assertJsonPath('data.0.born_at', $saleDay->toIso8601String())
            ->assertJsonPath('data.0.commission_amount', 5);

        // Mesma venda sob a regra de recebimento: só entra no corte que alcança o pagamento.
        CommissionRule::where('staff_id', $this->groomerMember->id)->delete();
        $this->assertTrue($receiptRule->fresh()->active);
        $this->open('2026-10-31')->assertJsonCount(0, 'data');
        $this->open('2026-11-03')->assertJsonCount(1, 'data')->assertJsonPath('data.0.trigger', 'on_receipt');
    }

    public function test_on_execution_is_born_when_the_appointment_is_completed_and_closes_by_its_date(): void
    {
        $this->makeCommissionRule(['percent' => 10, 'trigger' => 'on_execution']);
        $appointment = $this->appointmentInProgress();
        $this->pay($this->serviceSale($appointment->id));

        $this->open(now()->toDateString())->assertJsonCount(0, 'data');

        $this->travel(1)->days();
        $this->complete($appointment);
        $completedAt = $appointment->fresh()->completed_at;
        $this->assertNotNull($completedAt);

        // Corte antes da execução não alcança; o do dia da execução, sim.
        $this->open(now()->subDay()->toDateString())->assertJsonCount(0, 'data');
        $this->open(now()->toDateString())->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.trigger', 'on_execution')
            ->assertJsonPath('data.0.born_at', CarbonImmutable::instance($completedAt)->toIso8601String());

        $this->closeSettlement(now()->toDateString())->assertCreated()->assertJsonPath('data.total_amount', 5);
        $this->open(now()->toDateString())->assertJsonCount(0, 'data');
    }

    public function test_service_sold_without_appointment_falls_back_to_on_sale(): void
    {
        $this->makeCommissionRule(['percent' => 10, 'trigger' => 'on_execution']);
        $this->pay($this->serviceSale());

        $this->open(now()->toDateString())->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.trigger', 'on_sale')
            ->assertJsonPath('data.0.commission_amount', 5);
    }

    public function test_executed_service_on_an_unpaid_sale_stays_out_until_paid(): void
    {
        $this->makeCommissionRule(['percent' => 10, 'trigger' => 'on_execution']);
        $appointment = $this->appointmentInProgress();
        $saleId = $this->serviceSale($appointment->id);
        $this->complete($appointment);

        // Em aberto, e depois concluída no fiado: execução feita, venda sem pagamento → fora.
        $this->open(now()->toDateString())->assertJsonCount(0, 'data');
        CompanyClientAccount::create(['organization_id' => $this->clinic->id, 'client_id' => $this->tutor->id, 'allow_credit_sale' => true, 'credit_limit' => '1000.00']);
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/professional/sales/{$saleId}/complete")->assertOk()
            ->assertJsonPath('data.status', 'unpaid');
        $this->open(now()->toDateString())->assertJsonCount(0, 'data');

        $this->pay($saleId);
        $this->open(now()->toDateString())->assertJsonCount(1, 'data')->assertJsonPath('data.0.trigger', 'on_execution');
    }

    public function test_status_going_back_drops_an_open_commission_and_reverses_a_closed_one_into_a_deduction(): void
    {
        $this->makeCommissionRule(['percent' => 10, 'trigger' => 'on_execution']);
        $appointment = $this->appointmentInProgress();
        $this->pay($this->serviceSale($appointment->id));
        $this->complete($appointment);

        // Ainda não fechada: só sai do "em aberto".
        $this->reopen($appointment);
        $this->assertNull($appointment->fresh()->completed_at);
        $this->open(now()->toDateString())->assertJsonCount(0, 'data')->assertJsonPath('pending_deductions.total', '0.00');

        $this->complete($appointment);
        $this->closeSettlement(now()->toDateString())->assertCreated()->assertJsonPath('data.total_amount', 5);

        // Já fechada: fechamento intacto, linha estornada e desconto pendente de R$ 5,00.
        $this->reopen($appointment);
        $item = CommissionSettlementItem::sole();
        $this->assertNotNull($item->reversed_at);
        $this->assertSame('Agendamento deixou de estar concluído', $item->reversal_reason);
        $this->assertSame('5.00', (string) CommissionSettlement::sole()->total_amount);
        $this->assertSame('5.00', (string) CommissionClawback::sole()->amount);
        $this->open(now()->toDateString())->assertJsonCount(0, 'data')->assertJsonPath('pending_deductions.total', '5.00');

        // Concluído de novo: a comissão renasce e o próximo fechamento desconta o estorno — nada
        // some nem é pago duas vezes (5,00 bruto − 5,00 de desconto).
        $this->complete($appointment);
        $this->closeSettlement(now()->toDateString())->assertCreated()->assertJsonPath('data.total_amount', 0);
        $this->open(now()->toDateString())->assertJsonPath('pending_deductions.total', '0.00');
        $this->assertSame(['5.00', '0.00'], CommissionSettlement::orderBy('id')->pluck('total_amount')->map(fn ($v): string => (string) $v)->all());
    }

    public function test_appointment_going_back_does_not_touch_commission_closed_by_another_trigger(): void
    {
        $this->makeCommissionRule(['percent' => 10, 'trigger' => 'on_receipt']);
        $appointment = $this->appointmentInProgress();
        $this->pay($this->serviceSale($appointment->id));
        $this->complete($appointment);
        $this->closeSettlement(now()->toDateString())->assertCreated();

        $this->reopen($appointment);

        $this->assertNull(CommissionSettlementItem::sole()->reversed_at);
        $this->assertSame(0, CommissionClawback::count());
    }

    public function test_deleting_a_completed_appointment_reverses_its_closed_commission(): void
    {
        $this->makeCommissionRule(['percent' => 10, 'trigger' => 'on_execution']);
        $appointment = $this->appointmentInProgress();
        $this->pay($this->serviceSale($appointment->id));
        $this->complete($appointment);
        $this->closeSettlement(now()->toDateString())->assertCreated();

        $appointment->fresh()->delete();

        $this->assertSame('Agendamento concluído foi excluído', CommissionClawback::sole()->reason);
        $this->open(now()->toDateString())->assertJsonCount(0, 'data');
    }

    public function test_cancelling_a_sale_reverses_its_closed_commission(): void
    {
        $this->makeCommissionRule(['percent' => 10, 'trigger' => 'on_sale']);
        $saleId = $this->serviceSale();
        $this->pay($saleId);
        $this->closeSettlement(now()->toDateString())->assertCreated()->assertJsonPath('data.total_amount', 5);

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/professional/sales/{$saleId}/cancel", ['reason' => 'Cliente desistiu'])->assertOk();

        $this->assertSame('Venda cancelada: Cliente desistiu', CommissionSettlementItem::sole()->reversal_reason);
        $this->open(now()->toDateString())->assertJsonCount(0, 'data')->assertJsonPath('pending_deductions.total', '5.00');
    }

    public function test_profitability_drops_the_commission_of_an_execution_that_will_never_happen(): void
    {
        $this->makeCommissionRule(['percent' => 10, 'trigger' => 'on_execution']);
        $appointment = $this->appointmentInProgress();
        $saleId = $this->serviceSale($appointment->id);
        $this->pay($saleId);

        $this->profitability($saleId)->assertJsonPath('data.totals.commission_amount', '5.00');

        $this->actingAs($this->owner, 'sanctum')->putJson("/api/professional/appointments/{$appointment->id}", ['status' => 'cancelled'])->assertOk();

        $this->profitability($saleId)->assertJsonPath('data.totals.commission_amount', '0.00');
    }

    public function test_migration_converts_only_when_received_into_trigger_and_back(): void
    {
        $migration = require database_path('migrations/2026_11_19_100000_add_trigger_to_commission_rules.php');
        $migration->down();

        $insert = fn (bool $received): int => DB::table('commission_rules')->insertGetId([
            'organization_id' => $this->clinic->id, 'scope' => 'all', 'percent' => 5, 'calculation_base' => 'gross',
            'only_when_received' => $received, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $received = $insert(true);
        $notReceived = $insert(false);

        $migration->up();
        $this->assertSame('on_receipt', DB::table('commission_rules')->where('id', $received)->value('trigger'));
        $this->assertSame('on_sale', DB::table('commission_rules')->where('id', $notReceived)->value('trigger'));
        DB::table('commission_rules')->where('id', $notReceived)->update(['trigger' => 'on_execution']);

        $migration->down();
        $this->assertTrue((bool) DB::table('commission_rules')->where('id', $received)->value('only_when_received'));
        $this->assertFalse((bool) DB::table('commission_rules')->where('id', $notReceived)->value('only_when_received'));
        $migration->up();
    }

    private function serviceSale(?int $appointmentId = null): int
    {
        $this->openRegisterFor($this->owner);
        $saleId = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/professional/sales', ['kind' => 'sale', 'client_id' => $this->tutor->id])->assertCreated()->json('data.id');
        $this->postJson("/api/professional/sales/{$saleId}/items", [
            'sellable_type' => 'service', 'sellable_id' => $this->bath->id, 'staff_id' => $this->groomerMember->id, 'appointment_id' => $appointmentId,
        ])->assertCreated();

        return $saleId;
    }

    private function pay(int $saleId): void
    {
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/professional/sales/{$saleId}/receipts", [
            'payment_method_id' => $this->paymentMethodId($this->owner, 'cash'), 'amount' => 50,
        ])->assertCreated()->assertJsonPath('sale.status', 'paid');
    }

    private function appointmentInProgress(): Appointment
    {
        return Appointment::create([
            'professional_id' => $this->owner->id, 'organization_id' => $this->clinic->id, 'client_id' => $this->tutor->id,
            'pet_id' => $this->pet->id, 'appointment_date' => now()->toDateString(), 'appointment_time' => '09:00',
            'type' => 'grooming', 'status' => 'in_progress',
        ]);
    }

    private function complete(Appointment $appointment): void
    {
        $this->actingAs($this->owner, 'sanctum')->putJson("/api/professional/appointments/{$appointment->id}", ['status' => 'completed'])->assertOk();
    }

    /**
     * Não há porta HTTP de `completed` para trás (a máquina de estados trata como terminal): é
     * a correção direta no registro, que o observer tem de pegar do mesmo jeito.
     */
    private function reopen(Appointment $appointment): void
    {
        $appointment->fresh()->update(['status' => 'in_progress']);
    }

    private function open(string $until): TestResponse
    {
        return $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/professional/commissions/open?staff_id={$this->groomerMember->id}&received_until={$until}")->assertOk();
    }

    private function closeSettlement(string $until): TestResponse
    {
        return $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/commission-settlements', [
            'staff_id' => $this->groomerMember->id, 'period_from' => now()->subMonth()->toDateString(),
            'period_to' => $until, 'received_until' => $until,
        ]);
    }

    private function profitability(int $saleId): TestResponse
    {
        return $this->actingAs($this->owner, 'sanctum')->getJson("/api/professional/sales/{$saleId}/profitability")->assertOk();
    }
}
