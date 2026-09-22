<?php

namespace Tests\Feature\Commercial;

use App\Models\CashRegister;
use App\Models\CashRegisterMovement;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Ciclo de vida do caixa — critérios de aceite do docs/gap-simplesvet/01-caixa-pdv.md.
 */
class CashRegisterTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildClinic();
    }

    public function test_opening_records_the_float_as_a_cash_supply_and_provisions_default_payment_methods(): void
    {
        $registerId = $this->openRegisterFor($this->receptionist, 150);

        $this->assertSame(4, PaymentMethod::where('organization_id', $this->clinic->id)->count());

        $movement = CashRegisterMovement::where('cash_register_id', $registerId)->sole();
        $this->assertSame('supply', $movement->type->value);
        $this->assertEqualsWithDelta(150.0, (float) $movement->amount, 0.001);
        $this->assertSame('cash', $movement->paymentMethod->kind->value);
    }

    public function test_user_cannot_open_two_registers_at_the_same_time(): void
    {
        $registerId = $this->openRegisterFor($this->receptionist);

        $this->actingAs($this->receptionist, 'sanctum')
            ->postJson('/api/professional/cash-registers', ['opening_amount' => 50])
            ->assertStatus(422)
            ->assertJsonPath('code', 'cash_register_already_open')
            ->assertJsonPath('cash_register.id', $registerId);

        $this->assertSame(1, CashRegister::where('opened_by', $this->receptionist->id)->count());
    }

    public function test_two_different_people_can_each_have_an_open_register(): void
    {
        $this->openRegisterFor($this->receptionist);
        $this->openRegisterFor($this->owner);

        $this->assertSame(2, CashRegister::open()->count());
    }

    public function test_current_returns_null_without_an_open_register(): void
    {
        $this->actingAs($this->receptionist, 'sanctum')
            ->getJson('/api/professional/cash-registers/current')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_supply_and_withdrawal_are_recorded_with_the_right_sign(): void
    {
        $registerId = $this->openRegisterFor($this->receptionist, 100);

        $this->actingAs($this->receptionist, 'sanctum')
            ->postJson("/api/professional/cash-registers/{$registerId}/movements", [
                'type' => 'supply', 'amount' => 50, 'description' => 'Troco extra',
            ])->assertCreated()->assertJsonPath('data.type', 'supply');

        $this->postJson("/api/professional/cash-registers/{$registerId}/movements", [
            'type' => 'withdrawal', 'amount' => 30, 'description' => 'Sangria para o cofre',
        ])->assertCreated()->assertJsonPath('data.type', 'withdrawal');

        $signed = CashRegisterMovement::where('cash_register_id', $registerId)->orderBy('id')->get()
            ->map(fn (CashRegisterMovement $movement): float => $movement->signedAmount())->all();
        $this->assertEquals([100.0, 50.0, -30.0], $signed);

        $this->assertEqualsWithDelta(120.0, CashRegister::find($registerId)->expectedCashAmount(), 0.001);
    }

    public function test_manual_movement_cannot_be_a_sale_receipt(): void
    {
        $registerId = $this->openRegisterFor($this->receptionist);

        $this->actingAs($this->receptionist, 'sanctum')
            ->postJson("/api/professional/cash-registers/{$registerId}/movements", [
                'type' => 'sale_receipt', 'amount' => 999, 'description' => 'Inflar o caixa',
            ])->assertStatus(422);
    }

    public function test_manual_movement_rejects_a_payment_method_from_another_clinic(): void
    {
        $registerId = $this->openRegisterFor($this->receptionist);
        $outsider = User::factory()->professional()->create();
        $foreignMethod = PaymentMethod::create([
            'professional_id' => $outsider->id, 'name' => 'Dinheiro de fora', 'kind' => 'cash',
        ]);

        $this->actingAs($this->receptionist, 'sanctum')
            ->postJson("/api/professional/cash-registers/{$registerId}/movements", [
                'type' => 'supply', 'amount' => 10, 'description' => 'x', 'payment_method_id' => $foreignMethod->id,
            ])->assertStatus(422)->assertJsonValidationErrors('payment_method_id');
    }

    public function test_only_the_operator_can_post_movements_in_their_register(): void
    {
        $registerId = $this->openRegisterFor($this->receptionist);

        $this->actingAs($this->groomer, 'sanctum')
            ->postJson("/api/professional/cash-registers/{$registerId}/movements", [
                'type' => 'supply', 'amount' => 10, 'description' => 'Não é meu caixa',
            ])->assertForbidden();
    }

    public function test_closing_compares_expected_and_counted_per_payment_method_and_stores_the_difference(): void
    {
        $registerId = $this->openRegisterFor($this->receptionist, 100);
        $cash = $this->paymentMethodId($this->receptionist, 'cash');
        $pix = $this->paymentMethodId($this->receptionist, 'pix');

        // Movimento manual via Pix só para ter duas formas no esperado.
        $this->actingAs($this->receptionist, 'sanctum')
            ->postJson("/api/professional/cash-registers/{$registerId}/movements", [
                'type' => 'supply', 'amount' => 40, 'description' => 'Pix de reforço', 'payment_method_id' => $pix,
            ])->assertCreated();

        $response = $this->postJson("/api/professional/cash-registers/{$registerId}/close", [
            'counted' => [(string) $cash => 95, (string) $pix => 40],
        ])->assertOk();

        $response->assertJsonPath('data.status', 'closed');

        $rows = collect($response->json('data.closing_breakdown'))->keyBy('payment_method_id');
        $this->assertEquals(['expected' => 100, 'counted' => 95, 'difference' => -5], collect($rows[$cash])->only(['expected', 'counted', 'difference'])->all());
        $this->assertSame('Dinheiro', $rows[$cash]['payment_method_name']);
        $this->assertEquals(0, $rows[$pix]['difference']);

        $response
            ->assertJsonPath('data.closing_amount', 140)
            ->assertJsonPath('data.counted_amount', 135)
            ->assertJsonPath('data.difference', -5);
    }

    public function test_closed_register_rejects_new_movements_with_a_clear_422(): void
    {
        $registerId = $this->openRegisterFor($this->receptionist, 0);

        $this->actingAs($this->receptionist, 'sanctum')
            ->postJson("/api/professional/cash-registers/{$registerId}/close", ['counted' => ['none' => 0]])
            ->assertOk();

        $this->postJson("/api/professional/cash-registers/{$registerId}/movements", [
            'type' => 'supply', 'amount' => 10, 'description' => 'Depois de fechar',
        ])->assertStatus(422)
            ->assertJsonPath('code', 'cash_register_closed')
            ->assertJsonPath('message', 'Este caixa está fechado e não aceita novos movimentos. Abra um novo caixa para continuar vendendo.');
    }

    public function test_operator_does_not_see_expected_totals_while_the_register_is_open(): void
    {
        $this->openRegisterFor($this->receptionist, 100);

        $this->actingAs($this->receptionist, 'sanctum')
            ->getJson('/api/professional/cash-registers/current')
            ->assertOk()
            ->assertJsonMissingPath('data.totals')
            ->assertJsonPath('data.movements.0.amount', null)
            ->assertJsonPath('data.movements.0.amount_concealed', true)
            ->assertJsonPath('data.abilities.operate', true)
            ->assertJsonPath('data.abilities.preview', false);
    }

    public function test_movement_amounts_are_hidden_from_the_operator_until_closing_but_not_from_the_owner(): void
    {
        $registerId = $this->openRegisterFor($this->receptionist, 100);

        $this->actingAs($this->receptionist, 'sanctum')
            ->getJson("/api/professional/cash-registers/{$registerId}/movements")
            ->assertOk()
            ->assertJsonPath('data.0.signed_amount', null);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/professional/cash-registers/{$registerId}/movements")
            ->assertOk()
            ->assertJsonPath('data.0.signed_amount', 100);

        $this->actingAs($this->receptionist, 'sanctum')
            ->postJson("/api/professional/cash-registers/{$registerId}/close", ['counted' => ['none' => 0]])
            ->assertOk();

        $this->getJson("/api/professional/cash-registers/{$registerId}/movements")
            ->assertOk()
            ->assertJsonPath('data.0.signed_amount', 100);
    }

    public function test_a_plain_tutor_cannot_open_a_register_or_start_a_sale(): void
    {
        $this->actingAs($this->tutor, 'sanctum')
            ->postJson('/api/professional/cash-registers', ['opening_amount' => 0])
            ->assertForbidden();

        $this->postJson('/api/professional/sales', ['kind' => 'quote'])->assertForbidden();

        $this->assertSame(0, PaymentMethod::where('professional_id', $this->tutor->id)->count());
    }

    public function test_only_the_owner_settles_and_can_send_back_for_review(): void
    {
        $registerId = $this->openRegisterFor($this->receptionist, 0);
        $this->actingAs($this->receptionist, 'sanctum')
            ->postJson("/api/professional/cash-registers/{$registerId}/close", ['counted' => ['none' => 0]])
            ->assertOk();

        $this->postJson("/api/professional/cash-registers/{$registerId}/settle")->assertForbidden();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/professional/cash-registers/{$registerId}/review", ['reason' => 'Explique a diferença'])
            ->assertOk()
            ->assertJsonPath('data.status', 'under_review');

        $this->postJson("/api/professional/cash-registers/{$registerId}/settle")
            ->assertOk()
            ->assertJsonPath('data.status', 'settled');
    }

    public function test_listing_splits_my_registers_from_the_others(): void
    {
        $mine = $this->openRegisterFor($this->receptionist);
        $theirs = $this->openRegisterFor($this->owner);

        $this->actingAs($this->receptionist, 'sanctum')
            ->getJson('/api/professional/cash-registers?scope=mine')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine);

        $this->getJson('/api/professional/cash-registers?scope=others&date='.now()->toDateString())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $theirs);
    }

    public function test_a_register_from_another_clinic_is_not_visible(): void
    {
        $registerId = $this->openRegisterFor($this->receptionist);
        $outsider = User::factory()->professional()->create();

        $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/professional/cash-registers/{$registerId}")
            ->assertNotFound();
    }
}
