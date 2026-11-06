<?php

namespace Tests\Unit\Commercial\Package;

use App\Services\Commercial\Package\PackageSessionPricer;
use App\Support\Money\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Valor de sessão de pacote contra os casos de referência compartilhados com o front
 * (`tests/Fixtures/golden/package.json`, PLANO 2.7) e contra a propriedade "nada some": as
 * sessões somam o preço do pacote, centavo a centavo.
 */
class PackageSessionPricerTest extends TestCase
{
    /** @return array<string, array{0: array<string, mixed>}> */
    public static function goldenCases(): array
    {
        $data = json_decode((string) file_get_contents(__DIR__.'/../../../Fixtures/golden/package.json'), true, flags: JSON_THROW_ON_ERROR);

        return collect($data['packageSessions'])->mapWithKeys(fn (array $case): array => [$case['id'] => [$case]])->all();
    }

    /** @param  array<string, mixed>  $case */
    #[DataProvider('goldenCases')]
    public function test_golden_package_sessions(array $case): void
    {
        $result = (new PackageSessionPricer)->price($case['input']['package_price'], $case['input']['items']);
        $expected = $case['expected'];

        $this->assertSame($expected['list_total'], $result['list_total']);
        $this->assertSame($expected['discount_amount'], $result['discount_amount']);
        $this->assertSame($expected['discount_percent'], $result['discount_percent']);

        foreach ($expected['items'] as $index => $item) {
            $this->assertSame($item['service_id'], $result['items'][$index]['service_id']);
            $this->assertSame($item['list_total'], $result['items'][$index]['list_total']);
            $this->assertSame($item['package_total'], $result['items'][$index]['package_total']);
            $this->assertSame($item['sessions'], $result['items'][$index]['sessions']);
        }

        $sessions = collect($result['items'])->flatMap(fn (array $item): array => $item['sessions'])->all();
        $this->assertSame($case['input']['package_price'], Money::sum($sessions)->toString());
    }

    public function test_package_above_the_list_total_gives_a_negative_discount_and_no_list_total_gives_no_percent(): void
    {
        $pricer = new PackageSessionPricer;

        $above = $pricer->price('110.00', [['service_id' => 1, 'unit_price' => '50.00', 'quantity' => 2]]);
        $this->assertSame('-10.00', $above['discount_amount']);
        $this->assertSame('-10.00', $above['discount_percent']);
        $this->assertSame(['55.00', '55.00'], $above['items'][0]['sessions']);

        $free = $pricer->price('30.00', [['service_id' => 1, 'unit_price' => '0.00', 'quantity' => 3]]);
        $this->assertNull($free['discount_percent']);
        $this->assertSame(['10.00', '10.00', '10.00'], $free['items'][0]['sessions']);
    }
}
