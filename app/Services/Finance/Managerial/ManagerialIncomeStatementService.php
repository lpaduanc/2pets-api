<?php

namespace App\Services\Finance\Managerial;

use App\Enums\InsightPreset;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * DRE gerencial ("lucro real", docs/gap-petflow/PLANO.md, 1.7): o terceiro regime da
 * `GET reports/income-statement`. Vendas do snapshot de rentabilidade, despesas do financeiro
 * por `dre_treatment`; cada linha diz se é calculada (`computed`) ou somada de lançamentos
 * (`ledger`), e a calculada leva o atalho do BI de performance (1.6) com o mesmo período.
 */
final class ManagerialIncomeStatementService
{
    public const REGIME = 'managerial';

    public function __construct(
        private readonly ManagerialSalesTotals $sales,
        private readonly ManagerialLedgerTotals $ledger,
        private readonly ManagerialIncomeStatementCalculator $calculator,
    ) {}

    /** @return array<string, mixed> */
    public function generate(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $months = self::monthKeys($from, $to);
        $result = $this->calculator->calculate($months, $this->sales->byMonth($user, $from, $to), $this->ledger->byMonth($user, $from, $to));

        return [
            'regime' => self::REGIME,
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'months' => $months,
            'lines' => $this->lines($result, $from, $to),
            'margin_percent' => $result['margin_percent'],
            'break_even' => $result['break_even'],
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<array<string, mixed>>
     */
    private function lines(array $result, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $lines = [];

        foreach (ManagerialIncomeStatementCalculator::LINES as $key => [$label, $sign, $source, $metric]) {
            $lines[] = [
                'key' => $key,
                'label' => $label,
                'sign' => $sign,
                'source' => $source,
                ...$result['lines'][$key],
                'breakdown' => $key === 'cost_of_sales' ? $result['cost_breakdown'] : null,
                'drill_down' => $metric === null ? null : self::drillDown($metric, $from, $to),
            ];
        }

        return $lines;
    }

    /** @return array{indicator: string, presets: list<string>, metric: string, from: string, to: string} */
    private static function drillDown(string $metric, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [
            'indicator' => 'sales',
            'presets' => array_map(fn (InsightPreset $preset): string => $preset->value, InsightPreset::cases()),
            'metric' => $metric,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ];
    }

    /** @return list<string> */
    private static function monthKeys(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $months = [];

        for ($cursor = $from->startOfMonth(); $cursor->lessThanOrEqualTo($to); $cursor = $cursor->addMonthNoOverflow()) {
            $months[] = $cursor->format('Y-m');
        }

        return $months;
    }
}
