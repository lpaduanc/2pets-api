<?php

namespace App\Services\Fiscal;

use App\Models\Sale;
use App\Models\SaleReceipt;
use App\Support\Money\Money;

/**
 * Formas de pagamento do documento fiscal (grupo `pag/detPag`) — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfe-nfce.md §5.4. Uma venda mista (produto + serviço) paga num só
 * conjunto de recebimentos gera dois documentos; cada um leva os recebimentos ATIVOS da venda,
 * agrupados por natureza e rateados pelo maior resto até o total DO DOCUMENTO — Σ vPag = vNF,
 * centavo a centavo (a SEFAZ recusa NFC-e com pagamento menor que o total).
 */
final class FiscalPaymentAllocator
{
    /** @return list<array{method: string|null, amount: string}> */
    public function forSale(?Sale $sale, string $documentTotal): array
    {
        $weights = $this->weightsByKind($sale);

        if ($weights === [] || Money::of($documentTotal)->isZero()) {
            return [];
        }

        $shares = Money::of($documentTotal)->allocate($weights);

        return array_values(array_map(
            fn (string $kind, Money $share): array => ['method' => $kind === '' ? null : $kind, 'amount' => $share->toString()],
            array_keys($shares),
            $shares,
        ));
    }

    /** @return array<string, Money> natureza => soma recebida */
    private function weightsByKind(?Sale $sale): array
    {
        if ($sale === null) {
            return [];
        }

        return $sale->receipts()->active()->with('paymentMethod:id,kind')->get()
            ->groupBy(fn (SaleReceipt $receipt): string => (string) $receipt->paymentMethod?->kind?->value)
            ->map(fn ($receipts): Money => Money::sum($receipts->map(fn (SaleReceipt $receipt): Money => Money::of($receipt->amount))))
            ->filter(fn (Money $amount): bool => $amount->isPositive())
            ->all();
    }
}
