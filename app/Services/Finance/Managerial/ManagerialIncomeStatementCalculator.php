<?php

namespace App\Services\Finance\Managerial;

use App\Support\Money\Decimal;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * A conta da DRE gerencial (docs/gap-petflow/PLANO.md, 1.7), pura — sem banco. Por mês:
 *
 *   Receita bruta − Descontos − Devoluções − Impostos      = Receita líquida
 *   Receita líquida − CMV/CSP                              = Lucro bruto
 *   Lucro bruto − Taxas de cartão − Comissões              = Margem de contribuição
 *   MC + Outras receitas operacionais − Despesas operac.   = Resultado operacional
 *   Resultado operacional ± Resultado financeiro           = Lucro líquido
 *
 * As linhas de venda vêm somadas do snapshot `sale_item_profitability` (`ManagerialSalesTotals`),
 * as de lançamento, do financeiro. MC% = MC ÷ receita de vendas antes do imposto (a mesma do BI,
 * 1.5/1.6), e o ponto de equilíbrio sai de `BreakEvenCalculator`. Casos de referência em
 * `tests/Fixtures/golden/income-statement.json`.
 */
final class ManagerialIncomeStatementCalculator
{
    /** Ordem, rótulo, sinal e origem de cada linha. `metric`: a do BI que o drill-down abre. */
    public const LINES = [
        'gross_revenue' => ['Receita bruta de vendas', 'add', 'computed', 'gross_sales'],
        'discounts' => ['Descontos', 'subtract', 'computed', 'discount_percent'],
        'returns' => ['Devoluções', 'subtract', 'computed', 'net_revenue'],
        'sales_taxes' => ['Impostos sobre vendas', 'subtract', 'computed', 'taxes'],
        'net_revenue' => ['Receita líquida', 'result', 'computed', 'net_revenue'],
        'cost_of_sales' => ['CMV (produtos) / CSP (serviços)', 'subtract', 'computed', 'cost'],
        'gross_profit' => ['Lucro bruto', 'result', 'computed', 'contribution_margin'],
        'card_fees' => ['Taxas de cartão', 'subtract', 'computed', 'card_fees'],
        'commissions' => ['Comissões', 'subtract', 'computed', 'commissions'],
        // Lançamentos de categoria `variable_expense` (custo que acompanha o volume): reduzem a MC
        // e ficam fora das despesas fixas do ponto de equilíbrio.
        'variable_expenses' => ['Despesas variáveis', 'subtract', 'ledger', null],
        'contribution_margin' => ['Margem de contribuição', 'result', 'computed', 'contribution_margin'],
        'other_operating_revenue' => ['Outras receitas operacionais', 'add', 'ledger', null],
        'operating_expenses' => ['Despesas operacionais', 'subtract', 'ledger', null],
        'operating_result' => ['Resultado operacional', 'result', 'computed', null],
        'financial_result' => ['Resultado financeiro', 'add', 'ledger', null],
        'net_income' => ['Lucro líquido', 'result', 'computed', null],
    ];

    public function __construct(private readonly BreakEvenCalculator $breakEven) {}

    /**
     * @param  list<string>  $months
     * @param  array<string, array<string, string>>  $sales  por mês: gross_revenue, line_discounts, header_discounts, returns, taxes, sales_revenue, cost_products, cost_services, card_fees, commissions
     * @param  array<string, array<string, string>>  $ledger  por mês: operating_revenue, operating_expenses, variable_expenses, financial_revenue, financial_expenses
     * @return array{lines: array<string, array{months: array<string, string>, total: string}>, cost_breakdown: array{products: string, services: string}, margin_percent: string|null, break_even: array<string, mixed>}
     */
    public function calculate(array $months, array $sales, array $ledger): array
    {
        $byMonth = array_combine($months, array_map(fn (string $month): array => $this->month($sales[$month] ?? [], $ledger[$month] ?? []), $months));
        $lines = [];

        foreach (array_keys(self::LINES) as $key) {
            $values = array_map(fn (array $month): Money => $month[$key], $byMonth);
            $lines[$key] = ['months' => array_map(fn (Money $value): string => $value->toString(), $values), 'total' => Money::sum($values)->toString()];
        }

        $salesRevenue = self::sum($sales, $months, 'sales_revenue');
        $margin = Money::of($lines['contribution_margin']['total']);

        return [
            'lines' => $lines,
            'cost_breakdown' => ['products' => self::sum($sales, $months, 'cost_products')->toString(), 'services' => self::sum($sales, $months, 'cost_services')->toString()],
            'margin_percent' => $salesRevenue->isPositive() ? (string) $margin->toBigDecimal()->multipliedBy(100)->dividedBy($salesRevenue->toBigDecimal(), 2, RoundingMode::HalfUp) : null,
            'break_even' => $this->breakEven->calculate(Money::of($lines['operating_expenses']['total']), $margin, $salesRevenue),
        ];
    }

    /**
     * @param  array<string, string>  $sales
     * @param  array<string, string>  $ledger
     * @return array<string, Money>
     */
    private function month(array $sales, array $ledger): array
    {
        $value = fn (array $source, string $key): Money => Money::of($source[$key] ?? '0');
        $line = [
            'gross_revenue' => $value($sales, 'gross_revenue'),
            'discounts' => $value($sales, 'line_discounts')->plus($value($sales, 'header_discounts')),
            'returns' => $value($sales, 'returns'),
            'sales_taxes' => $value($sales, 'taxes'),
            'cost_of_sales' => $value($sales, 'cost_products')->plus($value($sales, 'cost_services')),
            'card_fees' => $value($sales, 'card_fees'),
            'commissions' => $value($sales, 'commissions'),
            'other_operating_revenue' => $value($ledger, 'operating_revenue'),
            'operating_expenses' => $value($ledger, 'operating_expenses'),
            'variable_expenses' => $value($ledger, 'variable_expenses'),
            'financial_result' => $value($ledger, 'financial_revenue')->minus($value($ledger, 'financial_expenses')),
        ];
        $line['net_revenue'] = $line['gross_revenue']->minus($line['discounts'])->minus($line['returns'])->minus($line['sales_taxes']);
        $line['gross_profit'] = $line['net_revenue']->minus($line['cost_of_sales']);
        $line['contribution_margin'] = $line['gross_profit']->minus($line['card_fees'])->minus($line['commissions'])->minus($line['variable_expenses']);
        $line['operating_result'] = $line['contribution_margin']->plus($line['other_operating_revenue'])->minus($line['operating_expenses']);
        $line['net_income'] = $line['operating_result']->plus($line['financial_result']);

        return $line;
    }

    /**
     * @param  array<string, array<string, string>>  $sales
     * @param  list<string>  $months
     */
    private static function sum(array $sales, array $months, string $key): Money
    {
        return Money::sum(array_map(fn (string $month): BigDecimal => Decimal::of($sales[$month][$key] ?? '0'), $months));
    }
}
