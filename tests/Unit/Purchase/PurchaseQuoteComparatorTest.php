<?php

namespace Tests\Unit\Purchase;

use App\Services\Purchase\PurchaseCostAllocator;
use App\Services\Purchase\Quotes\PurchaseQuoteComparator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Golden `purchase-quote.json` (PLANO 3.5), o mesmo que o front usa. */
class PurchaseQuoteComparatorTest extends TestCase
{
    /** @return array<string, array{0: array<string, mixed>}> */
    public static function cases(): array
    {
        $golden = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/golden/purchase-quote.json'), true, flags: JSON_THROW_ON_ERROR);

        return collect($golden['cases'])->mapWithKeys(fn (array $case): array => [$case['id'] => [$case]])->all();
    }

    /** @param  array<string, mixed>  $case */
    #[DataProvider('cases')]
    public function test_golden(array $case): void
    {
        $result = (new PurchaseQuoteComparator(new PurchaseCostAllocator))->compare($case['items'], $case['offers']);

        foreach ($case['expected']['real_unit_cost'] as $item => $costs) {
            $this->assertSame($costs, array_map(fn (array $offer): string => $offer['real_unit_cost'], $result['items'][$item]['offers']), "custo real de {$item}");
            $flags = array_keys(array_filter($result['items'][$item]['offers'], fn (array $offer): bool => $offer['is_lowest']));
            $this->assertSame($case['expected']['best'][$item] === null ? [] : [$case['expected']['best'][$item]], $flags, "vencedor de {$item}");
            $this->assertSame($case['expected']['best'][$item], $result['items'][$item]['best']);
        }

        foreach ($case['expected']['suppliers'] as $key => $totals) {
            $this->assertSame($totals, array_intersect_key($result['suppliers'][$key], $totals), "totais de {$key}");
        }
    }
}
