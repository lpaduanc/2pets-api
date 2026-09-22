<?php

namespace Tests\Feature\Finance;

use App\Enums\HolidayScope;
use App\Models\FinancialCategory;
use App\Models\Holiday;
use App\Services\Finance\DueDateService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * "Próximo dia útil" — critérios de aceite do
 * docs/gap-simplesvet/03-contas-a-pagar-receber-spec.md: sábado/domingo é não útil mesmo sem
 * cadastro, feriado cadastrado empurra a data, e a regra vale tanto para `DueDateService`
 * isolado quanto para a parcela real de um lançamento manual (spec 02).
 */
class DueDateBusinessDayTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    public function test_a_saturday_rolls_to_the_following_monday_even_without_any_holiday_registered(): void
    {
        $service = app(DueDateService::class);

        // 2026-11-21 é sábado, 2026-11-22 é domingo.
        $result = $service->nextBusinessDay(CarbonImmutable::parse('2026-11-21'));

        $this->assertSame('2026-11-23', $result->toDateString());
    }

    public function test_a_weekday_that_is_not_a_holiday_is_returned_unchanged(): void
    {
        $service = app(DueDateService::class);

        $result = $service->nextBusinessDay(CarbonImmutable::parse('2026-11-18'));

        $this->assertSame('2026-11-18', $result->toDateString());
    }

    public function test_a_holiday_registered_for_the_organization_pushes_the_date_forward(): void
    {
        $this->buildClinic();

        Holiday::create([
            'organization_id' => $this->clinic->id,
            'date' => '2026-11-19',
            'name' => 'Aniversário da cidade',
            'scope' => HolidayScope::MUNICIPAL->value,
            'recurring_annually' => false,
            'active' => true,
        ]);

        $service = app(DueDateService::class);

        $result = $service->nextBusinessDay(CarbonImmutable::parse('2026-11-19'), $this->clinic->id);

        $this->assertSame('2026-11-20', $result->toDateString());
    }

    /** Feriado de uma clínica não afeta o vencimento de outra (sem `organization_id`, só vê o nacional). */
    public function test_a_company_specific_holiday_does_not_leak_to_a_different_organization(): void
    {
        $this->buildClinic();

        Holiday::create([
            'organization_id' => $this->clinic->id,
            'date' => '2026-11-19',
            'name' => 'Recesso próprio',
            'scope' => HolidayScope::COMPANY->value,
            'recurring_annually' => false,
            'active' => true,
        ]);

        $service = app(DueDateService::class);

        $result = $service->nextBusinessDay(CarbonImmutable::parse('2026-11-19'), null);

        $this->assertSame('2026-11-19', $result->toDateString());
    }

    public function test_a_manual_financial_entry_installment_due_on_a_registered_holiday_is_created_on_the_next_business_day(): void
    {
        $this->buildClinic();

        Holiday::create([
            'organization_id' => $this->clinic->id,
            'date' => '2026-11-19',
            'name' => 'Aniversário da cidade',
            'scope' => HolidayScope::MUNICIPAL->value,
            'recurring_annually' => false,
            'active' => true,
        ]);

        $categoryId = FinancialCategory::create([
            'organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id,
            'name' => 'Fornecedores', 'nature' => 'expense', 'kind' => 'entry', 'active' => true,
        ])->id;

        $dueDate = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/professional/financial-entries', [
                'financial_category_id' => $categoryId,
                'description' => 'Conta que cai em feriado', 'nature' => 'expense',
                'due_date' => '2026-11-19', 'amount' => 40,
            ])->assertCreated()->json('data.0.due_date');

        $this->assertSame('2026-11-20', $dueDate);
    }
}
