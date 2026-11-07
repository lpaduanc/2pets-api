<?php

namespace App\Services\Purchase\Quotes;

use App\Enums\PurchaseQuoteSupplierStatus;
use App\Models\PurchaseQuote;
use App\Models\PurchaseQuoteItem;
use App\Models\PurchaseQuoteResponse;
use App\Models\PurchaseQuoteSupplier;
use App\Support\BusinessClock;

/**
 * Formatos de saída da cotação (PLANO 3.5): o recurso da clínica, o comparativo e o payload
 * público do fornecedor (lista branca: nome da clínica, itens e a proposta dele — nada de
 * CNPJ, endereço, custo, estoque ou os outros fornecedores).
 */
final class PurchaseQuotePresenter
{
    public function __construct(
        private readonly PurchaseQuoteTokenService $tokens,
        private readonly PurchaseQuoteComparator $comparator,
    ) {}

    /** @return array<string, mixed> */
    public function quote(PurchaseQuote $quote): array
    {
        return [
            'id' => $quote->id,
            'number' => (int) $quote->number,
            'status' => $quote->status->value,
            'status_label' => $quote->status->label(),
            'valid_until' => $quote->valid_until->toDateString(),
            'notes' => $quote->notes,
            'items' => $quote->items->map(fn (PurchaseQuoteItem $item): array => $this->item($item))->values()->all(),
            'suppliers' => $quote->suppliers->map(fn (PurchaseQuoteSupplier $s): array => $this->supplier($s))->values()->all(),
            'sent_at' => $quote->sent_at?->toIso8601String(),
            'closed_at' => $quote->closed_at?->toIso8601String(),
            'created_at' => $quote->created_at?->toIso8601String(),
        ];
    }

    /**
     * Por item, as ofertas de quem RESPONDEU, com o custo real (frete rateado) e o menor marcado.
     *
     * @return array{items: list<array<string, mixed>>, suppliers: list<array<string, mixed>>}
     */
    public function comparison(PurchaseQuote $quote): array
    {
        $responded = $quote->suppliers->filter(fn (PurchaseQuoteSupplier $s): bool => $s->status === PurchaseQuoteSupplierStatus::RESPONDED && $s->response !== null)->values();
        $result = $this->comparator->compare(
            $quote->items->map(fn (PurchaseQuoteItem $item): array => ['id' => $item->id, 'quantity' => (string) $item->quantity])->all(),
            $responded->map(fn (PurchaseQuoteSupplier $s): array => [
                'key' => $s->id,
                'freight' => (string) $s->response->freight,
                'delivery_days' => $s->response->delivery_days,
                'prices' => $s->response->items->mapWithKeys(fn ($line): array => [$line->purchase_quote_item_id => (string) $line->unit_price])->all(),
            ])->all(),
        );
        $byId = $responded->keyBy('id');

        return [
            'items' => $quote->items->map(fn (PurchaseQuoteItem $item): array => $this->item($item) + [
                'quote_item_id' => $item->id,
                'lowest_supplier_id' => isset($result['items'][$item->id]['best']) ? $byId[$result['items'][$item->id]['best']]->supplier_id : null,
                'offers' => collect($result['items'][$item->id]['offers'])->map(fn (array $offer, int $key): array => [
                    'quote_supplier_id' => $key,
                    'supplier_id' => $byId[$key]->supplier_id,
                    'supplier_name' => $this->supplierName($byId[$key]),
                ] + $offer)->values()->all(),
            ])->values()->all(),
            'suppliers' => $responded->map(fn (PurchaseQuoteSupplier $s): array => [
                'quote_supplier_id' => $s->id,
                'supplier_id' => $s->supplier_id,
                'supplier_name' => $this->supplierName($s),
                'freight' => (string) $s->response->freight,
                'delivery_days' => $s->response->delivery_days,
                'payment_terms' => $s->response->payment_terms,
                'valid_until' => $s->response->valid_until?->toDateString(),
                'proposal_expired' => self::proposalExpired($s->response),
            ] + $result['suppliers'][$s->id])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function publicView(PurchaseQuoteSupplier $quoteSupplier): array
    {
        $quote = $quoteSupplier->quote;
        $status = $quoteSupplier->effectiveStatus();

        return [
            'number' => (int) $quote->number,
            'clinic' => ['name' => PurchaseQuoteService::clinicName($quote)],
            'supplier' => ['name' => $this->supplierName($quoteSupplier)],
            'status' => $status->value,
            'status_label' => $status->label(),
            'valid_until' => $quoteSupplier->valid_until->toDateString(),
            'notes' => $quote->notes,
            'can_respond' => PurchaseQuotePublicService::canRespond($quoteSupplier),
            'items' => $quote->items->map(fn (PurchaseQuoteItem $item): array => [
                'id' => $item->id,
                'product_name' => $item->product?->name,
                'unit' => $item->product?->unit_of_sale,
                'quantity' => (string) $item->quantity,
            ])->values()->all(),
            'response' => $quoteSupplier->response === null ? null : $this->response($quoteSupplier->response),
            'responded_at' => $quoteSupplier->responded_at?->toIso8601String(),
            'rejected_at' => $quoteSupplier->rejected_at?->toIso8601String(),
        ];
    }

    public static function proposalExpired(PurchaseQuoteResponse $response): bool
    {
        return $response->valid_until !== null && $response->valid_until->toDateString() < BusinessClock::todayString();
    }

    /** @return array<string, mixed> */
    private function item(PurchaseQuoteItem $item): array
    {
        return [
            'id' => $item->id,
            'product_id' => $item->product_id,
            'product_name' => $item->product?->name,
            'unit' => $item->product?->unit_of_sale,
            'quantity' => (string) $item->quantity,
        ];
    }

    /** @return array<string, mixed> */
    private function supplier(PurchaseQuoteSupplier $quoteSupplier): array
    {
        $status = $quoteSupplier->effectiveStatus();

        return [
            'id' => $quoteSupplier->id,
            'supplier_id' => $quoteSupplier->supplier_id,
            'supplier_name' => $this->supplierName($quoteSupplier),
            'has_email' => filled($quoteSupplier->supplier?->email),
            'status' => $status->value,
            'status_label' => $status->label(),
            'valid_until' => $quoteSupplier->valid_until->toDateString(),
            'public_url' => $this->tokens->publicUrl($quoteSupplier),
            'emailed_at' => $quoteSupplier->emailed_at?->toIso8601String(),
            'viewed_at' => $quoteSupplier->viewed_at?->toIso8601String(),
            'responded_at' => $quoteSupplier->responded_at?->toIso8601String(),
            'rejected_at' => $quoteSupplier->rejected_at?->toIso8601String(),
            'rejection_reason' => $quoteSupplier->rejection_reason,
            'response' => $quoteSupplier->response === null ? null : $this->response($quoteSupplier->response),
        ];
    }

    /** @return array<string, mixed> */
    private function response(PurchaseQuoteResponse $response): array
    {
        return [
            'freight' => (string) $response->freight,
            'delivery_days' => $response->delivery_days,
            'payment_terms' => $response->payment_terms,
            'valid_until' => $response->valid_until?->toDateString(),
            'notes' => $response->notes,
            'items' => $response->items->map(fn ($line): array => ['quote_item_id' => $line->purchase_quote_item_id, 'unit_price' => (string) $line->unit_price])->values()->all(),
        ];
    }

    private function supplierName(PurchaseQuoteSupplier $quoteSupplier): ?string
    {
        return $quoteSupplier->supplier?->trade_name ?: $quoteSupplier->supplier?->legal_name;
    }
}
