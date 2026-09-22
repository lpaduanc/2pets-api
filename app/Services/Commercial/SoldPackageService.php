<?php

namespace App\Services\Commercial;

use App\Enums\SoldPackageStatus;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\ServicePackage;
use App\Models\SoldPackage;
use App\Models\SoldPackageConsumption;
use App\Models\SoldPackageItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ativação, consumo e cancelamento de pacote de serviços vendido — contrato
 * docs/gap-simplesvet/specs/10-pacotes-de-servicos-vendidos-spec.md.
 *
 * Chamado por `SaleService` nos dois pontos de extensão já existentes: `registerReceipt()`
 * (venda vira paga → ativa o saldo) e `cancel()` (venda paga cancelada → cancela o pacote).
 * Nunca chamado antes de a venda estar paga — pacote vendido e não pago não é crédito, é só
 * um orçamento (mesma regra do PDV para estoque).
 */
final class SoldPackageService
{
    /**
     * Cria um `sold_package` (+ saldo por serviço) para cada item da venda cujo `sellable` é
     * um `ServicePackage`. Chamado uma vez por venda paga, no mesmo ponto onde o estoque baixa.
     */
    public function activateFromSale(Sale $sale, User $user): void
    {
        foreach ($this->packageItems($sale) as $item) {
            $this->activateItem($sale, $item);
        }
    }

    /**
     * Cancela todo `sold_package` originado desta venda e trava consumo futuro. Reembolso
     * proporcional de sessão já usada fica fora do MVP (regra de negócio 6 do doc).
     */
    public function cancelForSale(Sale $sale): void
    {
        SoldPackage::query()
            ->where('sale_id', $sale->id)
            ->where('status', '!=', SoldPackageStatus::CANCELLED->value)
            ->get()
            ->each(fn (SoldPackage $package) => $package->update([
                'status' => SoldPackageStatus::CANCELLED,
                'cancelled_at' => now(),
            ]));
    }

    /**
     * Dá baixa de `$quantity` sessões de um serviço específico dentro do pacote. Rejeita com
     * 422 quando o pacote não está ativo (vencido/consumido/cancelado) ou quando a quantidade
     * excede o saldo — nas duas situações, sem alterar `quantity_used` (critério de aceite).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function consume(SoldPackage $package, array $attributes, User $user): SoldPackageConsumption
    {
        $item = $package->items()->whereKey((int) $attributes['sold_package_item_id'])->firstOrFail();
        $quantity = (int) $attributes['quantity'];

        abort_unless($package->canBeConsumed(), 422, sprintf(
            'Pacote %s não pode ser consumido (situação atual: %s).',
            $package->servicePackage->name,
            $package->effectiveStatus()->label(),
        ));

        if ($quantity > $item->quantityRemaining()) {
            throw ValidationException::withMessages([
                'quantity' => sprintf('Saldo insuficiente: restam %d sessão(ões).', $item->quantityRemaining()),
            ]);
        }

        return DB::transaction(function () use ($item, $quantity, $attributes, $user): SoldPackageConsumption {
            $item->registerConsumption($quantity);

            return $item->consumptions()->create([
                'appointment_id' => $attributes['appointment_id'] ?? null,
                'medical_record_id' => $attributes['medical_record_id'] ?? null,
                'quantity' => $quantity,
                'consumed_at' => now(),
                'user_id' => $user->id,
                'notes' => $attributes['notes'] ?? null,
            ]);
        });
    }

    /**
     * Um `sold_package` por item `ServicePackage` da venda, com o saldo inicial copiado da
     * composição do catálogo (`service_package_items`).
     *
     * @return \Illuminate\Support\Collection<int, SaleItem>
     */
    private function packageItems(Sale $sale): \Illuminate\Support\Collection
    {
        // Filtra por `sellable_type` ANTES do `with()`: um `morphTo` eager-carregado com uma
        // relação (`items`) que só existe num dos tipos possíveis (`ServicePackage`) quebraria
        // ao tentar montar a mesma relação para `Product`/`Service` se o grupo misto chegasse
        // ao `with()`.
        return $sale->items()
            ->where('sellable_type', ServicePackage::class)
            ->with('sellable.items')
            ->get();
    }

    private function activateItem(Sale $sale, SaleItem $item): void
    {
        /** @var ServicePackage $catalog */
        $catalog = $item->sellable;
        $soldAt = $sale->sold_at ?? now();

        DB::transaction(function () use ($sale, $item, $catalog, $soldAt): void {
            $package = SoldPackage::create([
                'organization_id' => $sale->organization_id,
                'professional_id' => $sale->professional_id,
                'sale_id' => $sale->id,
                'sale_item_id' => $item->id,
                'service_package_id' => $catalog->id,
                'client_id' => $sale->client_id,
                'pet_id' => $sale->pet_id,
                'sold_at' => $soldAt,
                'expires_at' => $catalog->expiresAtFrom($soldAt),
                'status' => SoldPackageStatus::ACTIVE,
            ]);

            foreach ($catalog->items as $catalogItem) {
                SoldPackageItem::create([
                    'sold_package_id' => $package->id,
                    'service_id' => $catalogItem->service_id,
                    'quantity_total' => $catalogItem->quantity,
                ]);
            }
        });
    }
}
