<?php

namespace Tests\Feature\Insights\Concerns;

use App\Enums\DiscountType;
use App\Enums\OrganizationRole;
use App\Enums\SaleKind;
use App\Enums\SaleStatus;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Pet;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Cenário do BI de vendas (docs/gap-simplesvet/specs/20-bi-produtividade-vendas-spec.md): uma
 * clínica (não petshop, de propósito — `OrganizationRole::VETERINARIAN` só vira `clinic_vet`,
 * que ganha `bi.view.own`, em organização `clinic`/`laboratory`) com dono, um veterinário
 * empregado e uma recepcionista (sem `bi.view.*` nenhum — ver catálogo de permissões).
 *
 * Vendas são criadas via MODEL direto, não pelo fluxo HTTP do PDV: o teste precisa controlar
 * `sold_at` livremente (datas passadas, dias da semana específicos), o que o balcão não expõe.
 */
trait BuildsInsightsFixtures
{
    protected Organization $clinic;

    protected User $owner;

    protected User $vet;

    protected User $receptionist;

    protected OrganizationMember $ownerMember;

    protected OrganizationMember $vetMember;

    protected OrganizationMember $receptionistMember;

    protected User $tutor;

    protected Pet $pet;

    protected Product $product;

    protected function buildClinic(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->clinic = Organization::factory()->create();

        $this->owner = User::factory()->professional()->create();
        $this->vet = User::factory()->professional()->create();
        // `tutor()`, não `professional()`: cadastro próprio de profissional (`user_type=vet`)
        // sempre reconcilia `vet_freelancer` (`bi.view.own`) por si só, INDEPENDENTE do cargo
        // na organização (`UserRoleReconciler::roleFromOwnRegistration()`) — mascararia
        // exatamente o cenário que este teste precisa provar (recepção sem `bi.view.*` nenhum).
        // Uma recepcionista sem prática profissional própria é o caso real, não um atalho.
        $this->receptionist = User::factory()->tutor()->create();

        $this->ownerMember = OrganizationMember::factory()->owner()->for($this->clinic, 'organization')
            ->create(['user_id' => $this->owner->id]);
        $this->vetMember = OrganizationMember::factory()->for($this->clinic, 'organization')
            ->create(['user_id' => $this->vet->id, 'role' => OrganizationRole::VETERINARIAN->value]);
        $this->receptionistMember = OrganizationMember::factory()->for($this->clinic, 'organization')
            ->create(['user_id' => $this->receptionist->id, 'role' => OrganizationRole::RECEPTIONIST->value]);

        $this->tutor = User::factory()->tutor()->create();
        $this->pet = Pet::factory()->create(['user_id' => $this->tutor->id]);

        $this->product = Product::create([
            'professional_id' => $this->owner->id,
            'organization_id' => $this->clinic->id,
            'name' => 'Ração Premium 3kg',
            'sku' => 'SKU-'.uniqid(),
            'price' => 100.00,
            'stock_quantity' => 1000,
            'controls_stock' => false,
            'allow_price_override' => false,
            'commission_percent' => 5,
            'is_active' => true,
        ]);
    }

    /**
     * Uma venda paga, com UM item do produto padrão do cenário, vendido pelo vínculo dado.
     * `soldAt` controla a data que o BI agrupa — sem ele, cai no instante do teste.
     */
    protected function sellProduct(
        OrganizationMember $staff,
        float $price,
        ?CarbonImmutable $soldAt = null,
        float $quantity = 1,
        float $discount = 0,
    ): Sale {
        $sale = Sale::create([
            'organization_id' => $this->clinic->id,
            'professional_id' => $staff->user_id,
            'client_id' => $this->tutor->id,
            'pet_id' => $this->pet->id,
            'kind' => SaleKind::SALE->value,
            'status' => SaleStatus::PAID->value,
            'discount_type' => DiscountType::NONE->value,
            'discount_value' => 0,
            'discount_amount' => 0,
            'subtotal' => 0,
            'total' => 0,
            'paid_amount' => 0,
            'created_by' => $staff->user_id,
            'sold_at' => $soldAt ?? now(),
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'sellable_type' => Product::class,
            'sellable_id' => $this->product->id,
            'description' => $this->product->name,
            'staff_id' => $staff->id,
            'quantity' => $quantity,
            'unit_price' => $price,
            'unit_cost' => 0,
            'discount' => $discount,
            'total' => round($quantity * $price - $discount, 2),
        ]);

        $sale->recalculateTotals();
        $sale->paid_amount = $sale->total;
        $sale->save();

        return $sale->fresh();
    }
}
