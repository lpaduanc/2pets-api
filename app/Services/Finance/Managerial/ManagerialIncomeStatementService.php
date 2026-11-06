<?php

namespace App\Services\Finance\Managerial;

use App\Enums\InsightPreset;
use App\Models\CostCenter;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
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
        private readonly ManagerialPackageRevenue $packages,
        private readonly CommercialScopeResolver $scope,
    ) {}

    /**
     * `$costCenter`: id do centro, `unallocated` ("Não rateado") ou null (todos). Com
     * `$breakdown`, devolve também a DRE de cada centro — a soma deles é a DRE total (PLANO 2.4).
     *
     * @return array<string, mixed>
     */
    public function generate(User $user, CarbonImmutable $from, CarbonImmutable $to, ?string $costCenter = null, bool $breakdown = false): array
    {
        $months = self::monthKeys($from, $to);
        $sales = ManagerialCostCenters::mergeCenters($this->sales->byCenterAndMonth($user, $from, $to), $this->packages->byCenterAndMonth($user, $from, $to));
        $ledger = $this->ledger->byCenterAndMonth($user, $from, $to);

        $pick = fn (array $byCenter): array => $costCenter === null ? ManagerialCostCenters::total($byCenter) : ($byCenter[$costCenter] ?? []);
        $result = $this->calculator->calculate($months, $pick($sales), $pick($ledger));

        return [
            'regime' => self::REGIME,
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'cost_center_id' => $costCenter,
            'months' => $months,
            'lines' => $this->lines($result, $from, $to),
            'margin_percent' => $result['margin_percent'],
            'break_even' => $result['break_even'],
            // PLANO 2.7: receita diferida de pacotes (passivo, fora do resultado) no período, sempre
            // da organização inteira (não é quebrada por centro).
            'package_deferral' => $this->packages->deferral($user, $from, $to),
            'cost_centers' => $breakdown ? $this->breakdown($user, $months, $sales, $ledger, $from, $to) : null,
        ];
    }

    /**
     * Uma DRE por centro com movimento no período, mais "Não rateado" se houver.
     *
     * @param  list<string>  $months
     * @param  array<string, array<string, array<string, string>>>  $sales
     * @param  array<string, array<string, array<string, string>>>  $ledger
     * @return list<array<string, mixed>>
     */
    private function breakdown(User $user, array $months, array $sales, array $ledger, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $keys = array_values(array_unique([...array_keys($sales), ...array_keys($ledger)]));
        $centers = $this->scope->scopeQuery(CostCenter::withTrashed(), $user)->whereIn('id', array_filter($keys, 'is_numeric'))->get()->keyBy('id');
        usort($keys, fn (string $a, string $b): int => [$a === ManagerialCostCenters::UNALLOCATED, $centers->get($a)?->name ?? ''] <=> [$b === ManagerialCostCenters::UNALLOCATED, $centers->get($b)?->name ?? '']);

        return array_map(function (string $key) use ($months, $sales, $ledger, $centers, $from, $to): array {
            $result = $this->calculator->calculate($months, $sales[$key] ?? [], $ledger[$key] ?? []);
            $center = $centers->get($key);

            return [
                'cost_center' => $center === null
                    ? ['id' => ManagerialCostCenters::UNALLOCATED, 'name' => 'Não rateado', 'type' => null]
                    : ['id' => $center->id, 'name' => $center->name, 'type' => $center->type->value],
                'lines' => array_map(fn (array $line): array => ['key' => $line['key'], 'total' => $line['total'], 'months' => $line['months'] ?? null], $this->lines($result, $from, $to)),
                'margin_percent' => $result['margin_percent'],
            ];
        }, $keys);
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
