<?php

namespace Tests\Unit\Stock;

use App\Services\Stock\Bulk\PackageOpeningCalculator;
use App\Services\Stock\Bulk\ScaleBarcodeParser;
use App\Services\Stock\Bulk\ScaleLineCalculator;
use App\Services\Stock\StockValuation;
use App\Support\Money\Quantity;
use Brick\Math\BigDecimal;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Abertura de embalagem e etiqueta da balança (PLANO 3.3) contra `stock-conversion.json` e `scale.json`. */
class BulkAndScaleGoldenTest extends TestCase
{
    /** @return array<string, array{0: array<string, mixed>}> */
    public static function openingCases(): array
    {
        return self::cases('stock-conversion.json', 'openings');
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function scaleCases(): array
    {
        return self::cases('scale.json', 'barcodes');
    }

    /** @param  array<string, mixed>  $case */
    #[DataProvider('openingCases')]
    public function test_golden_package_opening(array $case): void
    {
        $in = $case['input'];
        $valuation = fn (array $v): StockValuation => new StockValuation(Quantity::of($v['quantity']), BigDecimal::of($v['value']), BigDecimal::of($v['average_cost']));
        $result = (new PackageOpeningCalculator)->open($valuation($in['from']), $valuation($in['to']), Quantity::of($in['quantity']), $in['factor']);

        $this->assertSame($case['expected'], [
            'exit_value' => $result['exit_value'],
            'entered_quantity' => $result['entered_quantity'],
            'entering_unit_cost' => $result['entering_unit_cost'],
            'to_average_cost' => (string) $result['to_after']->averageCost,
            'to_value' => (string) $result['to_after']->value,
            'from_value' => (string) $result['from_after']->value,
        ]);
    }

    /** @param  array<string, mixed>  $case */
    #[DataProvider('scaleCases')]
    public function test_golden_scale_barcode(array $case): void
    {
        $in = $case['input'];

        if (! $case['expected']['valid']) {
            $this->expectException(InvalidArgumentException::class);
        }

        $parsed = (new ScaleBarcodeParser)->parse($in['barcode'], $in['settings']);
        $line = (new ScaleLineCalculator)->line($parsed['mode'], $parsed['value'], $in['price_per_kg']);

        $this->assertSame(array_diff_key($case['expected'], ['valid' => true]), ['scale_code' => $parsed['scale_code']] + $line);
    }

    public function test_prefix_and_length_checks(): void
    {
        $parser = new ScaleBarcodeParser;
        $other = '789123456789';

        $this->expectException(InvalidArgumentException::class);
        $parser->parse($other.ScaleBarcodeParser::checkDigit($other), null);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    private static function cases(string $file, string $key): array
    {
        $data = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/golden/'.$file), true, flags: JSON_THROW_ON_ERROR);

        return collect($data[$key])->mapWithKeys(fn (array $case): array => [$case['id'] => [$case]])->all();
    }
}
