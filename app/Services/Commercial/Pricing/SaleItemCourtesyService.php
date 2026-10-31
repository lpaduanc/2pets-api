<?php

namespace App\Services\Commercial\Pricing;

use App\Enums\SaleItemCourtesyKind;
use App\Exceptions\Commercial\SaleNotEditableException;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Marca/desmarca uma linha como brinde, cortesia ou retorno (docs/gap-petflow/PLANO.md, 1.4).
 * Venda aberta ou orçamento em rascunho; só quem tem `sales.grant_courtesy` no cargo da
 * organização da venda (`CourtesyAuthorizer`), senão 403. Cada marcação e desmarcação vai ao
 * `activity_log`.
 *
 * A marcação tira a linha do rateio do desconto do cabeçalho — a parte dela passa para as outras
 * linhas, então o teto de desconto delas é julgado de novo na hora (`SalePricingGuard`).
 */
final class SaleItemCourtesyService
{
    public function __construct(
        private readonly CourtesyAuthorizer $authorizer,
        private readonly SalePricingGuard $pricingRules,
    ) {}

    public function mark(Sale $sale, int $itemId, User $user, SaleItemCourtesyKind $kind, string $reason): SaleItem
    {
        $item = $this->editableItem($sale, $itemId, $user);

        return $this->apply($sale, $item, $user, function (SaleItem $item) use ($kind, $reason, $user): void {
            $item->markAsCourtesy($kind, $reason, $user);
        }, 'sale.item_courtesy_marked', __('pricing.courtesy_marked_log'));
    }

    public function unmark(Sale $sale, int $itemId, User $user): SaleItem
    {
        $item = $this->editableItem($sale, $itemId, $user);

        if (! $item->isCourtesy()) {
            throw ValidationException::withMessages(['courtesy' => __('pricing.courtesy_not_marked')]);
        }

        return $this->apply($sale, $item, $user, fn (SaleItem $item) => $item->unmarkCourtesy(), 'sale.item_courtesy_unmarked', __('pricing.courtesy_unmarked_log'));
    }

    private function editableItem(Sale $sale, int $itemId, User $user): SaleItem
    {
        if (! $sale->isEditable()) {
            throw new SaleNotEditableException($sale);
        }

        abort_unless($this->authorizer->allows($user, $sale), 403, __('pricing.courtesy_forbidden'));

        return $sale->items()->findOrFail($itemId);
    }

    /** @param  callable(SaleItem): void  $change */
    private function apply(Sale $sale, SaleItem $item, User $user, callable $change, string $event, string $description): SaleItem
    {
        $before = $this->pricingRules->snapshot($sale);
        $properties = ['sale_id' => $sale->id, 'unit_price_before' => (string) $item->unit_price];

        return DB::transaction(function () use ($sale, $item, $user, $change, $event, $description, $before, $properties): SaleItem {
            $change($item);
            $item->save();
            $sale->recalculateTotals();
            $sale->save();
            $this->pricingRules->assertChange($sale, $before, SalePricingChange::ITEM);

            activity('sales')->causedBy($user)->performedOn($item)->event($event)
                ->withProperties($properties + ['kind' => $item->courtesy_kind?->value, 'reason' => $item->courtesy_reason, 'unit_price_after' => (string) $item->unit_price])
                ->log($description);

            return $item->load(['staff.user', 'courtesyMarkedBy:id,name']);
        });
    }
}
