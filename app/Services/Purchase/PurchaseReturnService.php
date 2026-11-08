<?php

namespace App\Services\Purchase;

use App\Enums\PurchaseStatus;
use App\Enums\StockMovementType;
use App\Models\ProductBatch;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\User;
use App\Services\Stock\StockService;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Devolução (parcial) de compra ao fornecedor — spec docs/gap-petflow/specs/
 * fase4-fiscal-sem-envio.md §3.6. Mesmo efeito de estoque do cancelamento
 * (`PurchaseService::reverseItem`: saída `return_out` que desfaz o custo médio), só que pela
 * quantidade devolvida e pelo valor rateado (`PurchaseReturnLineCalculator`).
 *
 * NADA financeiro: crédito com o fornecedor, abatimento de parcela ou estorno é decisão pendente
 * do dono do produto (spec §8, item aberto 1). A NF-e de devolução sai em
 * `PurchaseReturnFiscalService`, por pedido explícito.
 */
final class PurchaseReturnService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly PurchaseReturnLineCalculator $calculator,
    ) {}

    /**
     * Mesma conta e mesmas validações do `create`, sem gravar.
     *
     * @param  list<array{purchase_item_id: int, quantity: int|float|string}>  $entries
     * @return list<array{item: PurchaseItem, quantity: Quantity, amounts: array<string, string>}>
     */
    public function preview(Purchase $purchase, array $entries): array
    {
        abort_unless($purchase->status === PurchaseStatus::RECEIVED, 422, 'Só compra recebida pode ser devolvida ao fornecedor.');

        $items = $purchase->items()->with('product')->get()->keyBy('id');

        return array_map(fn (array $entry): array => $this->line($items->get((int) $entry['purchase_item_id']), Quantity::of($entry['quantity'])), array_values($entries));
    }

    /** @param  list<array{purchase_item_id: int, quantity: int|float|string}>  $entries */
    public function create(Purchase $purchase, User $user, array $entries, string $reason): PurchaseReturn
    {
        return DB::transaction(function () use ($purchase, $user, $entries, $reason): PurchaseReturn {
            $lines = $this->preview($purchase, $entries);
            $return = PurchaseReturn::create([
                'organization_id' => $purchase->organization_id,
                'professional_id' => $purchase->professional_id,
                'purchase_id' => $purchase->id,
                'reason' => $reason,
                'user_id' => $user->id,
                'total' => Money::sum(array_map(fn (array $line): Money => Money::of($line['amounts']['total']), $lines))->toString(),
            ]);

            foreach ($lines as $line) {
                $this->returnLine($purchase, $return, $user, $line);
            }

            return $return->load(PurchaseReturn::RESOURCE_RELATIONS);
        });
    }

    /** @return array{item: PurchaseItem, quantity: Quantity, amounts: array<string, string>} */
    private function line(?PurchaseItem $item, Quantity $quantity): array
    {
        abort_if($item === null, 422, 'Item não pertence a esta compra.');

        $before = PurchaseReturnItem::returnedFor($item->id);
        $available = Quantity::of($item->quantity)->minus($before);

        abort_if(! $quantity->isPositive() || $quantity->isGreaterThan($available), 422, sprintf(
            'Quantidade inválida para "%s": %s disponível(is) para devolução.',
            $item->description_on_invoice ?? $item->product?->name,
            $available->max(0)->toBigDecimal()->strippedOfTrailingZeros()
        ));

        return ['item' => $item, 'quantity' => $quantity, 'amounts' => $this->calculator->line($item->only(['quantity', 'total_cost', 'discount', 'ipi_amount', 'icms_st_amount', 'landed_total_cost']), $before, $quantity)];
    }

    /** @param  array{item: PurchaseItem, quantity: Quantity, amounts: array<string, string>}  $line */
    private function returnLine(Purchase $purchase, PurchaseReturn $return, User $user, array $line): void
    {
        $item = $line['item'];
        PurchaseReturnItem::create($line['amounts'] + [
            'purchase_return_id' => $return->id,
            'purchase_item_id' => $item->id,
            'quantity' => $line['quantity']->toString(),
            'unit_cost' => (string) $item->unit_cost,
        ]);

        // Mesma saída do cancelamento (`PurchaseService::reverseItem`), na quantidade de estoque
        // (quantidade da compra × fator de conversão) e com o valor rateado da entrada.
        $this->stock->out($item->product, StockMovementType::RETURN_OUT, Quantity::of($line['quantity']->toBigDecimal()->multipliedBy((string) ($item->conversion_factor ?? 1))), [
            'unit_cost' => $item->effectiveUnitCost(),
            'total_cost' => $line['amounts']['stock_value'],
            'batch_id' => $this->batchId($item),
            'reverse_average_cost' => true,
            'reference' => $return,
            'user' => $user,
            'notes' => 'Devolução ao fornecedor — compra '.$purchase->code,
        ]);
    }

    private function batchId(PurchaseItem $item): ?int
    {
        if ($item->batch === null) {
            return null;
        }

        return ProductBatch::where('product_id', $item->product_id)->where('batch_code', $item->batch)->value('id');
    }
}
