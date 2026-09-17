<?php

namespace Tests\Unit;

use App\Support\PetAgeCalculator;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Extraído de `PetResource`/`PetPatientResource`/`PetDuplicateCandidateResource` para as três
 * nunca divergirem no rótulo de idade. Puro cálculo, sem banco: roda como Unit.
 */
class PetAgeCalculatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-16 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_label_for_less_than_one_month(): void
    {
        $age = PetAgeCalculator::calculate(Carbon::parse('2026-09-01'));

        $this->assertSame(0, $age['years']);
        $this->assertSame(0, $age['months']);
        $this->assertSame('0 mes', $age['label']);
    }

    public function test_label_for_several_months_without_a_full_year(): void
    {
        $age = PetAgeCalculator::calculate(Carbon::parse('2025-12-16'));

        $this->assertSame(0, $age['years']);
        $this->assertSame(9, $age['months']);
        $this->assertSame('9 meses', $age['label']);
    }

    public function test_label_for_exactly_one_year_without_remainder_months(): void
    {
        $age = PetAgeCalculator::calculate(Carbon::parse('2025-09-16'));

        $this->assertSame(1, $age['years']);
        $this->assertSame(0, $age['months']);
        $this->assertSame('1 ano', $age['label']);
    }

    public function test_label_for_years_and_months_combined(): void
    {
        $age = PetAgeCalculator::calculate(Carbon::parse('2016-08-27'));

        $this->assertSame(10, $age['years']);
        $this->assertSame(0, $age['months']);
        $this->assertSame('10 anos', $age['label']);
    }

    public function test_label_pluralizes_years_and_months_independently(): void
    {
        $age = PetAgeCalculator::calculate(Carbon::parse('2024-07-16'));

        $this->assertSame(2, $age['years']);
        $this->assertSame(2, $age['months']);
        $this->assertSame('2 anos e 2 meses', $age['label']);
    }
}
