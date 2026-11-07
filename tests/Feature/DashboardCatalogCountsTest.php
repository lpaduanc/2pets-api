<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/** `totalProducts` nas contagens do painel: produtos ativos do escopo, ao lado do estoque baixo. */
class DashboardCatalogCountsTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    public function test_total_products_counts_active_products_of_the_team(): void
    {
        $this->buildClinic();
        $this->makeProduct(['name' => 'Ração']);
        $this->makeProduct(['name' => 'Coleira']);
        // Mesmo escopo do `lowStockItems` (os profissionais do painel): produto de outro usuário fora dele não entra.
        $this->makeProduct(['name' => 'De outro'], organization: \App\Models\Organization::factory()->create(), by: \App\Models\User::factory()->professional()->create());
        $this->makeProduct(['name' => 'Fora de linha', 'is_active' => false]);
        // O painel exige o perfil profissional do usuário.
        \App\Models\Professional::factory()->create(['user_id' => $this->owner->id, 'professional_type' => 'petshop']);

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/professional/dashboard/stats')->assertOk()
            ->assertJsonPath('data.stats.totalProducts', 2);
    }
}
