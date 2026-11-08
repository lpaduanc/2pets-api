<?php

namespace Tests\Feature\Commercial\Concerns;

use App\Enums\OrganizationRole;
use App\Enums\TaxRegime;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Pet;
use App\Models\Product;
use App\Models\ProfessionalClient;
use App\Models\Service;
use App\Models\TaxProfile;
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

    /** Perfil tributário padrão da clínica (Fase 4 fiscal): produto sem perfil próprio herda este. */
    protected TaxProfile $defaultTaxProfile;

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

        // Fase 4 fiscal (docs/gap-petflow/specs/fase4-fiscal-sem-envio.md): emitir NFC-e/NF-e
        // exige regime, perfil tributário e NCM — o balcão padrão já nasce emitível.
        $this->clinic->forceFill(['tax_regime' => TaxRegime::SIMPLES_NACIONAL->value])->save();
        $this->defaultTaxProfile = $this->makeTaxProfile(['is_default' => true]);
    }

    /**
     * Perfil "Simples — revenda sem ST" (CSOSN 102) com todas as faixas de CFOP preenchidas.
     *
     * @param  array<string, mixed>  $overrides
     * @param  array<string, array<string, string>>  $cfops  [operação => [escopo => CFOP]] que substituem as padrão
     */
    protected function makeTaxProfile(array $overrides = [], array $cfops = [], ?Organization $organization = null): TaxProfile
    {
        $profile = TaxProfile::create($overrides + [
            'organization_id' => ($organization ?? $this->clinic)->id,
            'professional_id' => $this->owner->id,
            'name' => 'Simples — revenda',
            'origin' => '0',
            'icms_csosn' => '102',
            'pis_cst' => '49',
            'cofins_cst' => '49',
        ]);

        $slots = array_replace_recursive([
            'sale_consumer' => ['intrastate' => '5102', 'interstate' => '6108'],
            'sale_resale' => ['intrastate' => '5102', 'interstate' => '6102'],
            'return_from_customer' => ['intrastate' => '1202', 'interstate' => '2202'],
            'return_to_supplier' => ['intrastate' => '5202', 'interstate' => '6202'],
        ], $cfops);

        foreach ($slots as $operation => $scopes) {
            foreach ($scopes as $scope => $cfop) {
                $profile->cfops()->create(['operation' => $operation, 'scope' => $scope, 'cfop' => $cfop]);
            }
        }

        return $profile->load('cfops');
    }

    /** @param  array<string, mixed>  $overrides */
    protected function makeProduct(array $overrides = [], ?Organization $organization = null, ?User $by = null): Product
    {
        return Product::create($overrides + [
            'professional_id' => ($by ?? $this->owner)->id,
            'organization_id' => ($organization ?? $this->clinic)->id,
            'name' => 'Ração Premium 3kg',
            // NCM 2309.10.00 (alimento para cães e gatos): sem NCM a NFC-e/NF-e é recusada.
            'ncm' => '23091000',
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
