<?php

namespace Tests\Feature\Insights;

use App\Models\DashboardWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Insights\Concerns\BuildsInsightsFixtures;
use Tests\TestCase;

/**
 * Critérios de aceite: "widget adicionado ao dashboard persiste por usuário entre sessões",
 * "indicador favorito aparece na aba Favoritos" e "export CSV abre corretamente no Excel
 * pt-BR" (separador `;`, vírgula decimal, encoding — mesma prática de
 * `PriceListService::toCsv()`, doc 07).
 */
class InsightsPersistenceAndExportTest extends TestCase
{
    use BuildsInsightsFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildClinic();
    }

    public function test_a_dashboard_widget_persists_for_the_user_who_created_it(): void
    {
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/dashboard-widgets', [
            'indicator_key' => 'sales',
            'config' => ['dimension' => 'date', 'metric' => 'net_sales'],
            'position' => 0,
            'size' => 'medium',
        ])->assertCreated();

        $this->assertDatabaseHas('dashboard_widgets', [
            'user_id' => $this->owner->id,
            'organization_id' => $this->clinic->id,
            'indicator_key' => 'sales',
        ]);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/professional/dashboard-widgets')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_a_user_cannot_delete_another_users_dashboard_widget(): void
    {
        $widget = DashboardWidget::create([
            'user_id' => $this->owner->id,
            'organization_id' => $this->clinic->id,
            'indicator_key' => 'sales',
            'config' => [],
            'position' => 0,
            'size' => 'medium',
        ]);

        $this->actingAs($this->vet, 'sanctum')
            ->deleteJson("/api/professional/dashboard-widgets/{$widget->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('dashboard_widgets', ['id' => $widget->id, 'deleted_at' => null]);
    }

    public function test_a_favorite_indicator_shows_up_for_its_owner_and_can_be_removed(): void
    {
        $created = $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/favorite-indicators', [
            'indicator_key' => 'sales',
            'config' => ['dimension' => 'weekday', 'metric' => 'avg_ticket'],
        ])->assertCreated()->json('data.id');

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/professional/favorite-indicators')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/professional/favorite-indicators/{$created}")
            ->assertNoContent();

        $this->assertSoftDeleted('favorite_indicators', ['id' => $created]);
    }

    public function test_csv_export_uses_semicolon_separator_and_comma_decimal(): void
    {
        $this->sellProduct($this->vetMember, price: 123.45);

        $csv = $this->actingAs($this->owner, 'sanctum')->get(
            '/api/professional/insights/sales/export?'.http_build_query([
                'from' => now()->toDateString(),
                'to' => now()->toDateString(),
                'dimension' => 'date',
            ])
        )->assertOk()->streamedContent();

        $this->assertStringContainsString('Data;Venda;Status;Cliente;Animal;Produto;Qtd;Bruto;Desconto;Líquido', $csv);
        $this->assertStringContainsString('123,45', $csv);
        $this->assertStringNotContainsString('123.45', $csv);
    }
}
