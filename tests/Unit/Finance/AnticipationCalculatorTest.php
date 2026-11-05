<?php

namespace Tests\Unit\Finance;

use App\Enums\AnticipationMode;
use App\Services\Finance\Anticipation\AnticipationCalculator;
use App\Support\Money\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Antecipação de recebíveis (docs/gap-petflow/PLANO.md, 2.1) contra os casos de referência
 * compartilhados com o front (`tests/Fixtures/golden/anticipation.json`). Classe pura.
 */
class AnticipationCalculatorTest extends TestCase
{
    /** @return array<string, array{0: array<string, mixed>}> */
    public static function goldenCases(): array
    {
        $data = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/golden/anticipation.json'), true, flags: JSON_THROW_ON_ERROR);

        return collect($data['anticipation'])->mapWithKeys(fn (array $case): array => [$case['id'] => [$case]])->all();
    }

    /** @param  array<string, mixed>  $case */
    #[DataProvider('goldenCases')]
    public function test_golden_anticipation(array $case): void
    {
        $input = $case['input'];

        $this->assertSame($case['expected'], (new AnticipationCalculator)->calculate($input['items'], $input['anticipated_at'], AnticipationMode::from($input['mode']), $input['rate']));
    }

    /** @param  array<string, mixed>  $case */
    #[DataProvider('goldenCases')]
    public function test_nothing_disappears(array $case): void
    {
        $input = $case['input'];
        $result = (new AnticipationCalculator)->calculate($input['items'], $input['anticipated_at'], AnticipationMode::from($input['mode']), $input['rate']);

        $this->assertSame($result['gross_total'], Money::of($result['fee_total'])->plus($result['net_total'])->toString());
        $this->assertSame($result['fee_total'], Money::sum(array_column($result['items'], 'fee_amount'))->toString());
    }

    public function test_anticipating_on_the_deposit_day_costs_nothing_in_monthly_mode(): void
    {
        $result = (new AnticipationCalculator)->calculate([['receivable_id' => 1, 'net_amount' => '100.00', 'expected_date' => '2026-11-05']], '2026-11-05', AnticipationMode::MONTHLY_RATE, '2');

        $this->assertSame(['0.00', '100.00', 0], [$result['fee_total'], $result['net_total'], $result['items'][0]['days']]);
    }
}
