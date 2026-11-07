<?php

namespace Tests\Unit\Finance;

use App\Services\Finance\Managerial\BreakEvenCalculator;
use App\Services\Finance\Managerial\ManagerialIncomeStatementCalculator;
use App\Support\Money\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * DRE gerencial (docs/gap-petflow/PLANO.md, 1.7) contra os casos de referência compartilhados com
 * o front (`tests/Fixtures/golden/income-statement.json`). Classes puras.
 */
class ManagerialIncomeStatementCalculatorTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function golden(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/golden/income-statement.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function managerialCases(): array
    {
        return collect(self::golden()['managerial'])->mapWithKeys(fn (array $case): array => [$case['id'] => [$case]])->all();
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function breakEvenCases(): array
    {
        return collect(self::golden()['break_even_percent'])->mapWithKeys(fn (array $case): array => [$case['id'] => [$case]])->all();
    }

    /** @param  array<string, mixed>  $case */
    #[DataProvider('managerialCases')]
    public function test_golden_managerial_income_statement(array $case): void
    {
        $input = $case['input'];

        $result = (new ManagerialIncomeStatementCalculator(new BreakEvenCalculator))->calculate($input['months'], $input['sales'], $input['ledger']);

        // Casos anteriores à linha de despesas variáveis não a trazem no expected: ela tem de
        // valer zero neles, e o resto continua idêntico byte a byte.
        if (! array_key_exists('variable_expenses', $case['expected']['lines'])) {
            $this->assertSame('0.00', $result['lines']['variable_expenses']['total']);
            unset($result['lines']['variable_expenses']);
        }

        $this->assertSame($case['expected'], $result);
    }

    /** @param  array<string, mixed>  $case */
    #[DataProvider('managerialCases')]
    public function test_contribution_margin_line_equals_the_snapshot_formula(array $case): void
    {
        $result = (new ManagerialIncomeStatementCalculator(new BreakEvenCalculator))->calculate($case['input']['months'], $case['input']['sales'], $case['input']['ledger']);
        $lines = $result['lines'];

        // MC = receita de vendas − imposto − custo − taxa − comissão (a mesma do snapshot 1.5)
        // − despesas variáveis lançadas no financeiro.
        $snapshot = Money::sum(array_map(fn (array $month): Money => Money::of($month['sales_revenue'] ?? '0')->minus($month['taxes'] ?? '0')
            ->minus($month['cost_products'] ?? '0')->minus($month['cost_services'] ?? '0')->minus($month['card_fees'] ?? '0')->minus($month['commissions'] ?? '0'), $case['input']['sales']))
            ->minus(Money::sum(array_map(fn (array $month): string => $month['variable_expenses'] ?? '0', $case['input']['ledger'])));
        $this->assertSame($snapshot->toString(), $lines['contribution_margin']['total']);
    }

    public function test_variable_expense_lowers_margin_and_break_even_but_not_net_income(): void
    {
        $case = collect(self::golden()['managerial'])->firstWhere('id', 'dre-gerencial-despesa-variavel');
        $calculator = new ManagerialIncomeStatementCalculator(new BreakEvenCalculator);
        $asVariable = $calculator->calculate($case['input']['months'], $case['input']['sales'], $case['input']['ledger']);
        $asFixed = $calculator->calculate($case['input']['months'], $case['input']['sales'], ['2026-10' => ['operating_expenses' => '42.00', 'financial_expenses' => '5.00']]);

        $this->assertSame(['49.19', '61.19'], [$asVariable['lines']['contribution_margin']['total'], $asFixed['lines']['contribution_margin']['total']]);
        $this->assertSame(['150.65', '169.54'], [$asVariable['break_even']['amount'], $asFixed['break_even']['amount']]);
        $this->assertSame($asFixed['lines']['net_income']['total'], $asVariable['lines']['net_income']['total']);
    }

    /** @param  array<string, mixed>  $case */
    #[DataProvider('breakEvenCases')]
    public function test_golden_break_even_from_percent(array $case): void
    {
        $this->assertSame($case['expected'], (new BreakEvenCalculator)->fromPercent(Money::of($case['input']['fixed_expenses']), $case['input']['margin_percent']));
    }
}
