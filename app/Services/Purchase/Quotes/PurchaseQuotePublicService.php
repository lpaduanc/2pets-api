<?php

namespace App\Services\Purchase\Quotes;

use App\Enums\PurchaseQuoteStatus;
use App\Enums\PurchaseQuoteSupplierStatus;
use App\Exceptions\Purchase\PurchaseQuoteLinkException;
use App\Models\PurchaseQuoteSupplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lado do fornecedor, sem login (PLANO 3.5), no padrão do link do orçamento ao cliente:
 * token inválido → 404 (nunca dizemos se a cotação existe); cotação encerrada ou prazo vencido
 * sem resposta → 410 `purchase_quote_expired`; já respondida/recusada → 410
 * `purchase_quote_answered` com a proposta em `data` (só leitura), tanto no GET quanto no POST.
 * Resposta e recusa travam a linha (`lockForUpdate`) contra clique duplo.
 */
final class PurchaseQuotePublicService
{
    public function __construct(
        private readonly PurchaseQuoteTokenService $tokens,
        private readonly PurchaseQuotePresenter $presenter,
    ) {}

    public function view(string $token): PurchaseQuoteSupplier
    {
        $quoteSupplier = $this->resolve($token);
        $this->assertCanAnswer($quoteSupplier);

        if ($quoteSupplier->status === PurchaseQuoteSupplierStatus::REQUESTED) {
            $quoteSupplier->update(['status' => PurchaseQuoteSupplierStatus::VIEWED, 'viewed_at' => now()]);
        }

        return $quoteSupplier->fresh(['quote.items.product', 'supplier', 'response.items']);
    }

    /** @param  array<string, mixed>  $data */
    public function respond(string $token, array $data, ?string $ip): PurchaseQuoteSupplier
    {
        return DB::transaction(function () use ($token, $data, $ip): PurchaseQuoteSupplier {
            $quoteSupplier = $this->resolve($token, lock: true);
            $this->assertCanAnswer($quoteSupplier);
            $itemIds = $quoteSupplier->quote->items()->pluck('id')->all();

            foreach ($data['items'] as $index => $line) {
                if (! in_array((int) $line['quote_item_id'], $itemIds, true)) {
                    throw ValidationException::withMessages(["items.{$index}.quote_item_id" => 'Item não pertence a esta cotação.']);
                }
            }

            $response = $quoteSupplier->response()->create([
                'freight' => $data['freight'] ?? 0,
                'delivery_days' => $data['delivery_days'] ?? null,
                'payment_terms' => $data['payment_terms'] ?? null,
                'valid_until' => $data['valid_until'] ?? null,
                'notes' => $data['notes'] ?? null,
                'ip_address' => $ip,
            ]);

            foreach ($data['items'] as $line) {
                $response->items()->create(['purchase_quote_item_id' => (int) $line['quote_item_id'], 'unit_price' => (string) $line['unit_price']]);
            }

            $quoteSupplier->update(['status' => PurchaseQuoteSupplierStatus::RESPONDED, 'responded_at' => now(), 'viewed_at' => $quoteSupplier->viewed_at ?? now()]);

            return $quoteSupplier->fresh(['quote.items.product', 'supplier', 'response.items']);
        });
    }

    public function reject(string $token, ?string $reason): PurchaseQuoteSupplier
    {
        return DB::transaction(function () use ($token, $reason): PurchaseQuoteSupplier {
            $quoteSupplier = $this->resolve($token, lock: true);
            $this->assertCanAnswer($quoteSupplier);
            $quoteSupplier->update(['status' => PurchaseQuoteSupplierStatus::REJECTED, 'rejected_at' => now(), 'rejection_reason' => $reason, 'viewed_at' => $quoteSupplier->viewed_at ?? now()]);

            return $quoteSupplier->fresh(['quote.items.product', 'supplier', 'response.items']);
        });
    }

    public static function canRespond(PurchaseQuoteSupplier $quoteSupplier): bool
    {
        return $quoteSupplier->quote->status === PurchaseQuoteStatus::SENT && $quoteSupplier->effectiveStatus()->isAwaiting();
    }

    private function resolve(string $token, bool $lock = false): PurchaseQuoteSupplier
    {
        return $this->tokens->find($token, $lock) ?? abort(404, 'Link de cotação inválido.');
    }

    private function assertCanAnswer(PurchaseQuoteSupplier $quoteSupplier): void
    {
        $answered = match ($quoteSupplier->status) {
            PurchaseQuoteSupplierStatus::RESPONDED => 'Esta cotação já foi respondida.',
            PurchaseQuoteSupplierStatus::REJECTED => 'Esta cotação já foi recusada.',
            default => null,
        };

        if ($answered !== null) {
            $readOnly = $quoteSupplier->fresh(['quote.items.product', 'supplier', 'response.items']);

            throw (new PurchaseQuoteLinkException($answered, PurchaseQuoteLinkException::ANSWERED))->withData($this->presenter->publicView($readOnly));
        }

        if ($quoteSupplier->quote->status !== PurchaseQuoteStatus::SENT) {
            throw new PurchaseQuoteLinkException('Esta cotação foi encerrada pela clínica.', PurchaseQuoteLinkException::EXPIRED);
        }

        if ($quoteSupplier->effectiveStatus() === PurchaseQuoteSupplierStatus::EXPIRED) {
            throw new PurchaseQuoteLinkException(sprintf('O prazo desta cotação venceu em %s.', $quoteSupplier->valid_until->format('d/m/Y')), PurchaseQuoteLinkException::EXPIRED);
        }
    }
}
