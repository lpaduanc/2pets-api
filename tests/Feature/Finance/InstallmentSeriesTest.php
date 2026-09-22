<?php

namespace Tests\Feature\Finance;

use App\Models\FinancialCategory;
use App\Models\FinancialEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Parcelamento de lançamento manual — critérios de aceite do
 * docs/gap-simplesvet/02-financeiro-plano-de-contas-dre.md.
 */
class InstallmentSeriesTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    private int $expenseCategoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildClinic();

        $this->expenseCategoryId = FinancialCategory::create([
            'organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id,
            'name' => 'Aluguel', 'nature' => 'expense', 'kind' => 'entry', 'active' => true,
        ])->id;
    }

    public function test_six_monthly_installments_share_the_same_series_id_and_sum_the_total(): void
    {
        $data = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/professional/financial-entries', [
                'financial_category_id' => $this->expenseCategoryId,
                'description' => 'Reforma da recepção', 'nature' => 'expense',
                'due_date' => '2026-10-05', 'amount' => 100.00, 'installments' => 6,
            ])->assertCreated()->json('data');

        $this->assertCount(6, $data);
        $seriesId = $data[0]['series_id'];
        $this->assertNotNull($seriesId);
        $this->assertTrue(collect($data)->every(fn (array $e) => $e['series_id'] === $seriesId));
        $this->assertSame(range(1, 6), collect($data)->pluck('installment_number')->all());
        $this->assertEqualsWithDelta(100.0, collect($data)->sum('amount'), 0.001);
        $this->assertSame('2026-11-05', $data[1]['due_date']);
    }

    public function test_editing_installment_three_forward_does_not_touch_earlier_installments(): void
    {
        $seriesId = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/professional/financial-entries', [
                'financial_category_id' => $this->expenseCategoryId,
                'description' => 'Aluguel', 'nature' => 'expense',
                'due_date' => '2026-10-05', 'amount' => 60.00, 'installments' => 6,
            ])->assertCreated()->json('data.0.series_id');

        $updated = $this->patchJson("/api/professional/financial-entries/series/{$seriesId}", [
            'from_installment' => 3, 'description' => 'Aluguel reajustado',
        ])->assertOk()->json('data');

        $this->assertCount(4, $updated);
        $this->assertTrue(collect($updated)->every(fn (array $e) => $e['description'] === 'Aluguel reajustado'));

        $untouched = FinancialEntry::where('series_id', $seriesId)->whereIn('installment_number', [1, 2])->get();
        $this->assertTrue($untouched->every(fn (FinancialEntry $e) => $e->description === 'Aluguel'));
    }

    public function test_a_settled_installment_cannot_be_changed_in_a_batch_series_update(): void
    {
        $entries = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/professional/financial-entries', [
                'financial_category_id' => $this->expenseCategoryId,
                'description' => 'Aluguel', 'nature' => 'expense',
                'due_date' => '2026-10-05', 'amount' => 60.00, 'installments' => 3,
            ])->assertCreated()->json('data');

        $firstId = $entries[0]['id'];
        $seriesId = $entries[0]['series_id'];

        $this->postJson("/api/professional/financial-entries/{$firstId}/settle", ['paid_amount' => 20.00])->assertOk();

        $this->patchJson("/api/professional/financial-entries/series/{$seriesId}", ['from_installment' => 1, 'description' => 'Novo nome'])
            ->assertStatus(422);
    }
}
