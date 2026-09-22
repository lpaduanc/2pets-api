<?php

namespace Tests\Feature\Commercial\Concerns;

use App\Models\Pet;
use App\Models\Service;
use App\Models\ServicePackage;
use App\Models\SoldPackage;
use App\Models\User;

/**
 * Cenário de pacote de serviços vendido do doc gap-simplesvet/10, montado sobre a mesma
 * clínica/tutor/animal de `BuildsCounterFixtures`.
 */
trait BuildsPackageFixtures
{
    /** @param  array<string, mixed>  $overrides */
    protected function makeServicePackage(array $overrides = [], ?Service $service = null, int $quantity = 10): ServicePackage
    {
        $service ??= $this->makeService(['name' => 'Banho porte médio']);

        $package = ServicePackage::create($overrides + [
            'professional_id' => $this->owner->id,
            'organization_id' => $this->clinic->id,
            'name' => 'Pacote 10 banhos',
            'price' => 400.00,
            'validity_type' => 'days_from_sale',
            'validity_days' => 90,
            'active' => true,
        ]);

        $package->items()->create(['service_id' => $service->id, 'quantity' => $quantity]);

        return $package->fresh('items');
    }

    /**
     * Vende `$package` para `$pet` (via `$client`, dono do pet) através do fluxo real de
     * venda (item + recebimento total), e devolve o `SoldPackage` já ativado. Usado pelos
     * testes que não estão exercitando a ativação em si, só o que acontece depois dela.
     */
    protected function sellPackageToPet(ServicePackage $package, Pet $pet, User $client): SoldPackage
    {
        $this->openRegisterFor($this->receptionist);

        $saleId = $this->postJson('/api/professional/sales', [
            'kind' => 'sale', 'client_id' => $client->id, 'pet_id' => $pet->id,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/professional/sales/{$saleId}/items", [
            'sellable_type' => 'package', 'sellable_id' => $package->id,
        ])->assertCreated();

        $cash = $this->paymentMethodId($this->receptionist, 'cash');
        $this->postJson("/api/professional/sales/{$saleId}/receipts", [
            'payment_method_id' => $cash, 'amount' => (float) $package->price,
        ])->assertCreated();

        return SoldPackage::where('sale_id', $saleId)->firstOrFail();
    }
}
