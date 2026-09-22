<?php

namespace Tests\Feature\Commercial\Concerns;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Pet;
use App\Models\Product;
use App\Models\ProfessionalClient;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Cenário de balcão do doc gap-simplesvet/01: uma clínica com dono, recepcionista e groomer,
 * um tutor cliente com um animal, e um produto e um serviço no catálogo.
 */
trait BuildsCounterFixtures
{
    protected Organization $clinic;

    protected User $owner;

    protected User $receptionist;

    protected User $groomer;

    protected OrganizationMember $ownerMember;

    protected OrganizationMember $receptionistMember;

    protected OrganizationMember $groomerMember;

    protected User $tutor;

    protected Pet $pet;

    protected function buildClinic(): void
    {
        // Item 22 do backlog gap-simplesvet: `OrganizationMember::observe(...)` reconcilia o
        // papel Spatie do cargo automaticamente a partir daqui — sem o catálogo seedado, a
        // reconciliação vira no-op (`UserRoleReconciler::onlyRolesRegisteredInCatalog()`) e
        // nenhum membro criado abaixo teria a permissão que rotas `permission:...` exigem.
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->clinic = Organization::factory()->petshop()->create();

        $this->owner = User::factory()->professional()->create();
        $this->receptionist = User::factory()->professional()->create();
        $this->groomer = User::factory()->professional()->create();

        $this->ownerMember = OrganizationMember::factory()->owner()->for($this->clinic, 'organization')
            ->create(['user_id' => $this->owner->id]);
        $this->receptionistMember = OrganizationMember::factory()->for($this->clinic, 'organization')
            ->create(['user_id' => $this->receptionist->id, 'role' => OrganizationRole::RECEPTIONIST->value]);
        $this->groomerMember = OrganizationMember::factory()->for($this->clinic, 'organization')
            ->create(['user_id' => $this->groomer->id, 'role' => OrganizationRole::GROOMER->value]);

        $this->tutor = User::factory()->tutor()->create(['name' => 'Tutora Balcão']);
        $this->pet = Pet::factory()->create(['user_id' => $this->tutor->id]);

        // Cliente do DONO, não da recepcionista: o balcão enxerga os clientes da equipe toda.
        ProfessionalClient::create(['professional_id' => $this->owner->id, 'client_id' => $this->tutor->id]);
    }

    /** @param  array<string, mixed>  $overrides */
    protected function makeProduct(array $overrides = [], ?Organization $organization = null, ?User $by = null): Product
    {
        return Product::create($overrides + [
            'professional_id' => ($by ?? $this->owner)->id,
            'organization_id' => ($organization ?? $this->clinic)->id,
            'name' => 'Ração Premium 3kg',
            'sku' => 'SKU-'.uniqid(),
            'price' => 100.00,
            'stock_quantity' => 10,
            'controls_stock' => true,
            'allow_price_override' => false,
            'commission_percent' => 5,
            'is_active' => true,
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    protected function makeService(array $overrides = [], ?Organization $organization = null, ?User $by = null): Service
    {
        return Service::create($overrides + [
            'professional_id' => ($by ?? $this->groomer)->id,
            'organization_id' => ($organization ?? $this->clinic)->id,
            'name' => 'Banho porte médio',
            'category' => 'grooming',
            'duration' => 60,
            'price' => 60.00,
            'commission_percent' => 30,
            'allow_price_override' => true,
            'active' => true,
        ]);
    }

    protected function openRegisterFor(User $user, float $openingAmount = 100): int
    {
        return $this->actingAs($user, 'sanctum')
            ->postJson('/api/professional/cash-registers', ['opening_amount' => $openingAmount])
            ->assertCreated()
            ->json('data.id');
    }

    /** Id da forma cadastrada de uma natureza (as padrão nascem na primeira listagem). */
    protected function paymentMethodId(User $user, string $kind): int
    {
        $methods = $this->actingAs($user, 'sanctum')
            ->getJson('/api/professional/payment-methods?direction=in')
            ->assertOk()
            ->json('data');

        return collect($methods)->firstWhere('kind', $kind)['id'];
    }
}
