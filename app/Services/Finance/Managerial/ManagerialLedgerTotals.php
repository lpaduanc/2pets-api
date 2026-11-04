<?php

namespace App\Services\Finance\Managerial;

use App\Enums\DreTreatment;
use App\Enums\FinancialEntryStatus;
use App\Enums\FinancialNature;
use App\Models\FinancialEntry;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Finance\SaleReturnFinancialEntryRecorder;
use App\Services\Finance\SaleRevenueReversalRecorder;
use Carbon\CarbonImmutable;

/**
 * Linhas de lançamento da DRE gerencial (PLANO 1.7), por mês de competência (`accrual_date`,
 * como o mês da venda): só categoria `operating` (despesa → despesas operacionais; receita →
 * outras receitas operacionais, ex. fatura de atendimento) e `financial` (resultado financeiro).
 * Lançamento sem categoria conta como `operating`.
 *
 * Os lançamentos que o PDV gera da própria venda (receita, devolução, estorno do cancelamento)
 * ficam SEMPRE de fora, mesmo se a clínica mudar a categoria deles: o valor já está no snapshot
 * e contaria duas vezes.
 */
final class ManagerialLedgerTotals
{
    private const SALE_REFERENCES = ['sale', SaleReturnFinancialEntryRecorder::REFERENCE_TYPE, SaleRevenueReversalRecorder::REFERENCE_TYPE];

    public function __construct(private readonly CommercialScopeResolver $scope) {}

    /** @return array<string, array<string, string>> por mês `YYYY-MM` */
    public function byMonth(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $treatment = "COALESCE(financial_categories.dre_treatment, '".DreTreatment::OPERATING->value."')";
        $rows = $this->scope->scopeQuery(FinancialEntry::query(), $user)
            ->leftJoin('financial_categories', 'financial_categories.id', '=', 'financial_entries.financial_category_id')
            ->where('financial_entries.status', '!=', FinancialEntryStatus::CANCELLED->value)
            ->where(fn ($query) => $query->whereNull('financial_entries.reference_type')->orWhereNotIn('financial_entries.reference_type', self::SALE_REFERENCES))
            ->whereBetween('financial_entries.accrual_date', [$from->toDateString(), $to->toDateString()])
            ->whereRaw("{$treatment} IN (?, ?)", [DreTreatment::OPERATING->value, DreTreatment::FINANCIAL->value])
            ->groupByRaw("to_char(financial_entries.accrual_date, 'YYYY-MM'), financial_entries.nature, {$treatment}")
            ->selectRaw("to_char(financial_entries.accrual_date, 'YYYY-MM') AS month, financial_entries.nature AS nature, {$treatment} AS treatment, SUM(financial_entries.net_amount) AS amount")
            ->toBase()
            ->get();

        $months = [];

        foreach ($rows as $row) {
            $months[$row->month][self::lineFor($row->treatment, $row->nature)] = (string) $row->amount;
        }

        return $months;
    }

    private static function lineFor(string $treatment, string $nature): string
    {
        $isRevenue = $nature === FinancialNature::REVENUE->value;

        if ($treatment === DreTreatment::FINANCIAL->value) {
            return $isRevenue ? 'financial_revenue' : 'financial_expenses';
        }

        return $isRevenue ? 'operating_revenue' : 'operating_expenses';
    }
}
