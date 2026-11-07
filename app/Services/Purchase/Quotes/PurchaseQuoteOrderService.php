<?php

namespace App\Services\Purchase\Quotes;

use App\Enums\PurchaseQuoteStatus;
use App\Enums\PurchaseQuoteSupplierStatus;
use App\Models\PurchaseOrder;
use App\Models\PurchaseQuote;
use App\Models\PurchaseQuoteSupplier;
use App\Models\User;
use App\Services\Purchase\PurchaseOrderService;
use App\Support\BusinessClock;
use App\Support\Money\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Gerar pedido" da cotação (PLANO 3.5): vencedor escolhido por item → um pedido em RASCUNHO
 * por fornecedor vencedor (`PurchaseOrderService`), com o PREÇO cotado como custo do item (o
 * frete não é custo do produto no pedido: vai na observação e entra no custo real quando a nota
 * chegar, regra 1.2). Previsão de entrega = hoje + prazo da proposta. A cotação fecha.
 *
 * Tudo ou nada: qualquer vencedor inválido (fornecedor que não respondeu, item que ele não
 * cotou, proposta vencida) → 422 apontando `winners.N` e nenhum pedido criado.
 */
final class PurchaseQuoteOrderService
{
    public function __construct(private readonly PurchaseOrderService $orders) {}

    /**
     * @param  list<array{quote_item_id: int, supplier_id: int}>  $winners
     * @return Collection<int, PurchaseOrder>
     */
    public function generate(PurchaseQuote $quote, User $user, array $winners): Collection
    {
        abort_unless($quote->status === PurchaseQuoteStatus::SENT, 422, 'Só cotação enviada (e ainda não encerrada) gera pedidos.');

        $quote->loadMissing(['items', 'suppliers.response.items']);
        $lines = $this->resolve($quote, $winners);

        return DB::transaction(function () use ($quote, $user, $lines): Collection {
            $orders = collect($lines)->groupBy(fn (array $line): int => $line['quote_supplier']->id)
                ->map(fn (Collection $group): PurchaseOrder => $this->order($quote, $user, $group))->values();
            $quote->update(['status' => PurchaseQuoteStatus::CLOSED, 'closed_at' => now()]);

            return $orders;
        });
    }

    /** @param  Collection<int, array{quote_supplier: PurchaseQuoteSupplier, product_id: int, quantity: string, unit_price: string}>  $group */
    private function order(PurchaseQuote $quote, User $user, Collection $group): PurchaseOrder
    {
        $quoteSupplier = $group->first()['quote_supplier'];
        $response = $quoteSupplier->response;
        $notes = array_filter([
            sprintf('Gerado da cotação nº %d.', $quote->number),
            Money::of($response->freight)->isPositive() ? 'Frete cotado: R$ '.number_format((float) $response->freight, 2, ',', '.').'.' : null,
            $response->payment_terms ? "Condição: {$response->payment_terms}." : null,
        ]);

        return $this->orders->create($user, [
            'supplier_id' => $quoteSupplier->supplier_id,
            'expected_at' => $response->delivery_days === null ? null : BusinessClock::today()->addDays($response->delivery_days)->toDateString(),
            'notes' => implode(' ', $notes),
            'items' => $group->map(fn (array $line): array => ['product_id' => $line['product_id'], 'quantity' => $line['quantity'], 'unit_cost' => $line['unit_price']])->values()->all(),
        ]);
    }

    /**
     * @param  list<array{quote_item_id: int, supplier_id: int}>  $winners
     * @return list<array{quote_supplier: PurchaseQuoteSupplier, product_id: int, quantity: string, unit_price: string}>
     */
    private function resolve(PurchaseQuote $quote, array $winners): array
    {
        $items = $quote->items->keyBy('id');
        $suppliers = $quote->suppliers->keyBy('supplier_id');
        $lines = [];
        $errors = [];

        foreach ($winners as $index => $winner) {
            $item = $items->get((int) $winner['quote_item_id']);
            $quoteSupplier = $suppliers->get((int) $winner['supplier_id']);
            $price = $quoteSupplier?->response?->items->firstWhere('purchase_quote_item_id', $item?->id)?->unit_price;
            $error = match (true) {
                $item === null => 'Item não pertence a esta cotação.',
                $quoteSupplier === null || $quoteSupplier->status !== PurchaseQuoteSupplierStatus::RESPONDED => 'Este fornecedor não respondeu a cotação.',
                $price === null => 'Este fornecedor não cotou este item.',
                PurchaseQuotePresenter::proposalExpired($quoteSupplier->response) => 'A proposta deste fornecedor está vencida.',
                default => null,
            };

            if ($error !== null) {
                $errors["winners.{$index}"] = $error;

                continue;
            }

            $lines[] = ['quote_supplier' => $quoteSupplier, 'product_id' => $item->product_id, 'quantity' => (string) $item->quantity, 'unit_price' => (string) $price];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $lines;
    }
}
