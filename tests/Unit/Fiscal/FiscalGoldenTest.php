<?php

namespace Tests\Unit\Fiscal;

use App\DataTransferObjects\Fiscal\FiscalTaxRule;
use App\Enums\FiscalCfopOperation;
use App\Enums\FiscalDocumentKind;
use App\Services\Fiscal\Calculation\FiscalCfopSelector;
use App\Services\Fiscal\Calculation\FiscalNumberGaps;
use App\Services\Fiscal\Calculation\FiscalReturnLineMirror;
use App\Services\Fiscal\Calculation\FiscalTaxCalculator;
use App\Services\Fiscal\Calculation\TaxProfileChain;
use App\Services\Purchase\PurchaseReturnLineCalculator;
use App\Support\Fiscal\FiscalAccessKey;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Fiscal sem transmissão contra os casos de referência compartilhados com o front
 * (`tests/Fixtures/golden/fiscal.json`, spec docs/gap-petflow/specs/fase4-fiscal-sem-envio.md §7).
 * Só classes puras — nada aqui toca banco nem container.
 */
class FiscalGoldenTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function golden(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/golden/fiscal.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  list<array<string, mixed>>  $cases
     * @return array<string, array{0: array<string, mixed>}>
     */
    private static function byId(array $cases): array
    {
        return collect($cases)->mapWithKeys(fn (array $case): array => [$case['id'] => [$case]])->all();
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function itemCases(): array
    {
        return self::byId(self::golden()['items']);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function cfopCases(): array
    {
        return self::byId(self::golden()['cfop']);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function profileCases(): array
    {
        return self::byId(self::golden()['profile_resolution']['cases']);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function saleReturnCases(): array
    {
        return self::byId(self::golden()['sale_returns']);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function purchaseReturnCases(): array
    {
        return self::byId(self::golden()['purchase_returns']);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function gapCases(): array
    {
        return self::byId(self::golden()['invalidation']['gaps']);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function violationCases(): array
    {
        return self::byId(self::golden()['invalidation']['violations']);
    }

    /** @param  array<string, mixed>  $case */
    #[DataProvider('itemCases')]
    public function test_golden_item_distribution_and_taxes(array $case): void
    {
        $calculator = new FiscalTaxCalculator;
        $lines = array_map(fn (array $line): array => ['gross' => Money::of($line['gross']), 'discount' => Money::of($line['discount'])], $case['input']['lines']);
        $distributed = $calculator->distribute($lines, Money::of($case['input']['header_discount']), Money::of($case['input']['surcharge']));

        $this->assertSame($case['expected']['lines'], array_map(fn (array $line): array => array_map(fn (Money $value): string => $value->toString(), $line), $distributed));
        $this->assertSame($case['expected']['document_total'], Money::sum(array_column($distributed, 'total'))->toString());

        foreach ($case['taxes'] as $taxCase) {
            $rule = FiscalTaxRule::fromArray($taxCase['rule']);

            foreach ($taxCase['expected'] as $key => $expected) {
                $this->assertSame($expected, array_intersect_key($calculator->taxes($distributed[$key]['total'], $rule), $expected), "{$case['id']} / {$key}");
            }
        }
    }

    /** Propriedade do rateio: Σ totais dos itens = bruto − desconto + acréscimo, sempre. */
    public function test_item_totals_always_add_up_to_the_document_total(): void
    {
        $calculator = new FiscalTaxCalculator;
        mt_srand(4242);

        for ($round = 0; $round < 500; $round++) {
            $lines = [];
            for ($index = 0; $index < mt_rand(1, 6); $index++) {
                $gross = Money::ofCents(mt_rand(1, 50000));
                $lines[] = ['gross' => $gross, 'discount' => Money::ofCents(mt_rand(0, (int) (string) $gross->cents()))];
            }

            $net = Money::sum(array_map(fn (array $line): Money => $line['gross']->minus($line['discount']), $lines));
            $header = Money::ofCents(mt_rand(0, (int) (string) $net->cents()));
            $surcharge = Money::ofCents(mt_rand(0, 3000));
            $distributed = $calculator->distribute($lines, $header, $surcharge);

            $this->assertTrue($net->minus($header)->plus($surcharge)->isEqualTo(Money::sum(array_column($distributed, 'total'))));
        }
    }

    /** @param  array<string, mixed>  $case */
    #[DataProvider('cfopCases')]
    public function test_golden_cfop_selection(array $case): void
    {
        $selector = new FiscalCfopSelector;
        $scope = $selector->scope($case['emitter_state'], $case['recipient_state'], FiscalDocumentKind::from($case['kind']));
        $cfops = self::golden()['cfop_profiles'][$case['profile']];

        $this->assertSame($case['expected_scope'], $scope->value);
        $this->assertSame($case['expected'], $selector->select($cfops, FiscalCfopOperation::from($case['operation']), $scope));
    }

    /** @param  array<string, mixed>  $case */
    #[DataProvider('profileCases')]
    public function test_golden_tax_profile_resolution_order(array $case): void
    {
        $tree = self::golden()['profile_resolution'];
        $groups = collect($tree['groups'])->mapWithKeys(fn (array $group, string $id): array => [(int) $id => $group])->all();
        $resolved = (new TaxProfileChain($groups, $tree['active'], $case['default']))->resolve($case['own'], $case['group']);

        $this->assertSame($case['expected'], $resolved === null ? null : [$resolved[0], $resolved[1]->value]);
    }

    /** @param  array<string, mixed>  $case */
    #[DataProvider('saleReturnCases')]
    public function test_golden_sale_return_mirrors_the_original_item_cumulatively(array $case): void
    {
        $mirror = new FiscalReturnLineMirror(new FiscalTaxCalculator);
        $rule = FiscalTaxRule::fromArray($case['rule']);

        foreach ($case['slices'] as $slice) {
            $line = $mirror->mirror($case['original'], $rule, ['before' => Quantity::of($slice['before']), 'quantity' => Quantity::of($slice['quantity'])], $case['refund_surcharge']);

            $this->assertSame($slice['expected'], array_intersect_key($line, $slice['expected']), "{$case['id']} antes {$slice['before']}");
        }
    }

    /** @param  array<string, mixed>  $case */
    #[DataProvider('purchaseReturnCases')]
    public function test_golden_purchase_return_line(array $case): void
    {
        $calculator = new PurchaseReturnLineCalculator;

        foreach ($case['slices'] as $slice) {
            $this->assertSame($slice['expected'], $calculator->line($case['item'], Quantity::of($slice['before']), Quantity::of($slice['quantity'])), "{$case['id']} antes {$slice['before']}");
        }
    }

    /** @param  array<string, mixed>  $case */
    #[DataProvider('gapCases')]
    public function test_golden_numbering_gaps(array $case): void
    {
        $this->assertSame($case['expected'], (new FiscalNumberGaps)->gaps($case['used'], $case['invalidated'], $case['start_number'], $case['next_number']));
    }

    /** @param  array<string, mixed>  $case */
    #[DataProvider('violationCases')]
    public function test_golden_invalidation_range_rules(array $case): void
    {
        $this->assertSame($case['expected'], (new FiscalNumberGaps)->violations($case['from'], $case['to'], $case['reason'], $case['used'], $case['invalidated']));
    }

    /**
     * Exemplo do Manual de Orientação do Contribuinte (chave 5206 0433 0099 1100 2506 5501 2000
     * 0007 8002 6730 1615): DV 5 — o mesmo módulo 11 que o driver próprio vai usar.
     */
    public function test_access_key_check_digit_follows_the_module_11_rule(): void
    {
        $this->assertSame('5', FiscalAccessKey::checkDigit('5206043300991100250655012000000780026730161'));
        $this->assertSame('0', FiscalAccessKey::checkDigit(str_repeat('0', 43)));
    }
}
