<?php

namespace App\Services\Inventory;

use App\Enums\InventoryMovementType;
use App\Exceptions\Inventory\ExpiredBatchException;
use App\Exceptions\Inventory\InsufficientStockException;
use App\Exceptions\Inventory\InventoryAccessDeniedException;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Baixa de estoque por ato clínico atômico (vacina/vermífugo) — docs/vinculo-estoque-aplicacao-
 * clinica.md itens 1, 4, 5 e 6.
 *
 * `lockAndValidate()` deve rodar DENTRO de uma `DB::transaction()` já aberta pelo chamador, e
 * ANTES de criar o registro clínico: assim uma falha de estoque nunca deixa uma vacinação ou
 * vermifugação órfã para o rollback desfazer — ela nem chega a ser criada.
 */
final class ClinicalStockDeductionService
{
    public function __construct(
        private readonly InventoryScopeResolver $scopeResolver,
    ) {}

    /**
     * @throws InventoryAccessDeniedException Item de outra organização/profissional.
     * @throws ExpiredBatchException Lote vencido na data do ato, sem `confirm_expired`.
     * @throws InsufficientStockException Saldo menor que 1 unidade.
     */
    public function lockAndValidate(
        int $inventoryId,
        User $requester,
        CarbonInterface $applicationDate,
        bool $confirmExpired,
    ): Inventory {
        $inventory = Inventory::query()->lockForUpdate()->findOrFail($inventoryId);

        $this->guardAccess($inventory, $requester);
        $this->guardExpiry($inventory, $applicationDate, $confirmExpired);
        $this->guardStock($inventory);

        return $inventory;
    }

    /**
     * Decrementa 1 unidade e grava o movimento no mesmo golpe. `organization_id` espelha o dono
     * do item NO MOMENTO do movimento (item 6 do parecer) — nunca recalculado depois.
     */
    public function recordDeduction(
        Inventory $inventory,
        Model $reference,
        InventoryMovementType $type,
        int $professionalId,
        bool $confirmedExpired,
    ): void {
        $inventory->decrement('quantity');

        InventoryMovement::create([
            'inventory_id' => $inventory->id,
            'organization_id' => $inventory->organization_id,
            'professional_id' => $professionalId,
            'type' => $type,
            'quantity_delta' => -1,
            'reference_type' => $reference::class,
            'reference_id' => $reference->getKey(),
            'notes' => $confirmedExpired
                ? 'Lote vencido aplicado mediante confirmação explícita do profissional.'
                : null,
        ]);
    }

    private function guardAccess(Inventory $inventory, User $requester): void
    {
        if (! $this->scopeResolver->userCanAccess($inventory, $requester)) {
            throw new InventoryAccessDeniedException;
        }
    }

    private function guardExpiry(Inventory $inventory, CarbonInterface $applicationDate, bool $confirmExpired): void
    {
        $isExpired = $inventory->expiry_date !== null && $inventory->expiry_date->lt($applicationDate);

        if ($isExpired && ! $confirmExpired) {
            throw new ExpiredBatchException($inventory);
        }
    }

    private function guardStock(Inventory $inventory): void
    {
        if ($inventory->quantity < 1) {
            throw new InsufficientStockException($inventory);
        }
    }
}
