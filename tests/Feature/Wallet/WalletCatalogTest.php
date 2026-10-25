<?php

namespace Tests\Feature\Wallet;

use App\Models\Pet;
use App\Models\TutorExpense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WalletCatalogTest extends TestCase
{
    use RefreshDatabase;

    private User $tutor;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-25 15:00:00');
        $this->tutor = User::factory()->tutor()->create();
        Sanctum::actingAs($this->tutor);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_catalog_lists_categories_with_their_subcategories(): void
    {
        $categories = collect($this->getJson('/api/wallet/catalog')->assertOk()->json('data.categories'));

        $health = $categories->firstWhere('value', 'health');
        $this->assertSame('Saúde', $health['label']);
        $this->assertContains('vaccine', array_column($health['subcategories'], 'value'));
        $food = $categories->firstWhere('value', 'food');
        $this->assertSame('monthly', collect($food['subcategories'])->firstWhere('value', 'dry_food')['suggested_frequency']);
    }

    public function test_single_pet_is_preselected(): void
    {
        $pet = Pet::factory()->create(['user_id' => $this->tutor->id]);

        $this->assertSame($pet->id, $this->getJson('/api/wallet/catalog')->json('data.default_pet_id'));

        Pet::factory()->create(['user_id' => $this->tutor->id]);
        $this->assertNull($this->getJson('/api/wallet/catalog')->json('data.default_pet_id'));
    }

    public function test_quick_picks_bring_most_repeated_expense_with_latest_amount(): void
    {
        $toddy = Pet::factory()->create(['user_id' => $this->tutor->id, 'name' => 'Toddy']);
        foreach (['2026-08-01' => 179.9, '2026-09-01' => 184.9, '2026-10-01' => 189.9] as $date => $amount) {
            TutorExpense::factory()->create([
                'user_id' => $this->tutor->id,
                'pet_id' => $toddy->id,
                'category' => 'food',
                'subcategory' => 'dry_food',
                'amount' => $amount,
                'spent_on' => $date,
                'payment_method' => 'pix',
            ]);
        }
        TutorExpense::factory()->create([
            'user_id' => $this->tutor->id,
            'category' => 'accessories',
            'subcategory' => 'toy',
            'amount' => 30,
            'spent_on' => '2026-10-02',
        ]);

        $picks = $this->getJson('/api/wallet/catalog')->json('data.quick_picks');

        $this->assertSame('dry_food', $picks[0]['subcategory']);
        $this->assertEquals(189.9, $picks[0]['amount']);
        $this->assertSame('Toddy', $picks[0]['pet']['name']);
        $this->assertSame('pix', $picks[0]['payment_method']);
        $this->assertSame(3, $picks[0]['times_used']);
        $this->assertSame('toy', $picks[1]['subcategory']);
    }

    public function test_quick_picks_ignore_old_habits_and_other_tutors(): void
    {
        TutorExpense::factory()->create(['user_id' => $this->tutor->id, 'subcategory' => 'toy', 'category' => 'accessories', 'spent_on' => '2025-01-01']);
        TutorExpense::factory()->create(['spent_on' => '2026-10-01']);

        $this->assertSame([], $this->getJson('/api/wallet/catalog')->json('data.quick_picks'));
    }
}
