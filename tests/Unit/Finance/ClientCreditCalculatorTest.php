<?php

namespace Tests\Unit\Finance;

use App\Services\Finance\ClientCredit\ClientCreditCalculator;
use App\Support\Money\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Crediário parcelado (docs/gap-petflow/PLANO.md, 2.2) contra os casos de referência compartilhados
 * com o front (`tests/Fixtures/golden/client-credit.json`). Classe pura.
 */
class ClientCreditCalculatorTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function golden(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/golden/client-credit.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    private static function section(string $name): array
    {
        return collect(self::golden()[$name])->mapWithKeys(fn (array $case): array => [$case['id'] => [$case]])->all();
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function scheduleCases(): array
    {
        return self::section('schedule');
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function dueCases(): array
    {
        return self::section('due');
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function fifoCases(): array
    {
        return self::section('fifo');
    }

    /** @param  array<string, mixed>  $case */
    #[DataProvider('scheduleCases')]
    public function test_golden_schedule(array $case): void
    {
        $input = $case['input'];
        $schedule = (new ClientCreditCalculator)->schedule($input['total'], $input['count'], $input['first_due_date']);

        $this->assertSame($case['expected'], $schedule);
        $this->assertSame(Money::of($input['total'])->toString(), Money::sum(array_column($schedule, 'amount'))->toString());
    }

    /** @param  array<string, mixed>  $case */
    #[DataProvider('dueCases')]
    public function test_golden_fees(array $case): void
    {
        $input = $case['input'];

        $this->assertSame($case['expected'], (new ClientCreditCalculator)->dueOn($input['installment'], $input['policy'], $input['date']));
    }

    /** @param  array<string, mixed>  $case */
    #[DataProvider('fifoCases')]
    public function test_golden_fifo(array $case): void
    {
        $input = $case['input'];

        $this->assertSame($case['expected'], (new ClientCreditCalculator)->allocate($input['installments'], $input['policy'], $input['date'], $input['payment'], $input['fee_discount']));
    }

    public function test_after_fees_are_settled_the_late_fee_does_not_come_back_and_interest_restarts(): void
    {
        $policy = ['late_fee_percent' => '2.00', 'monthly_interest_percent' => '1.0000', 'grace_days' => 0];
        $installment = ['amount' => '100.00', 'paid_principal' => '44.30', 'due_date' => '2026-02-10', 'fees_settled_on' => '2026-02-20', 'open_fees_paid' => '0.00'];

        // 55,70 restantes, 10 dias depois do acerto: só juros de 10 dias (0,19), sem nova multa.
        $this->assertSame(['55.70', 20, '0.00', '0.19', '0.19', '55.89'], array_values((new ClientCreditCalculator)->dueOn($installment, $policy, '2026-03-02')));
    }
}
