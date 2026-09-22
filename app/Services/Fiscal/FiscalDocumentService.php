<?php

namespace App\Services\Fiscal;

use App\Contracts\FiscalProviderGateway;
use App\Enums\FiscalDocumentKind;
use App\Enums\FiscalDocumentStatus;
use App\Models\FiscalDocument;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\Finance\FinancialOverviewService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Emissão fiscal a partir de uma venda — contrato
 * docs/gap-simplesvet/05-emissao-fiscal-nfe-nfce-nfse-spec.md.
 *
 * Roteamento: venda só de serviço a PF emite NFS-e; só de produto emite NFC-e/NF-e
 * (`FiscalOperation::productDocument()`, já existe desde o item 01); venda mista emite as
 * duas — nunca um documento só. Revenda (`fiscal_operation.isResale()`) sempre NF-e, nunca
 * NFC-e, mesmo presencial.
 *
 * Type-hint na INTERFACE (`FiscalProviderGateway`), nunca na implementação — o bind fica em
 * `AppServiceProvider` (DIP).
 */
final class FiscalDocumentService
{
    public function __construct(
        private readonly FiscalProviderGateway $gateway,
        private readonly FinancialOverviewService $overview,
    ) {}

    /**
     * @return Collection<int, FiscalDocument>
     */
    public function issueFromSale(Sale $sale, User $actor): Collection
    {
        abort_if($sale->items->isEmpty(), 422, 'Venda sem item não emite documento fiscal.');
        $this->assertNotAlreadyIssued($sale);

        $split = $this->overview->saleTotalsByItemType($sale);
        $hasProduct = $sale->items->contains(fn ($item): bool => $item->sellable_type === Product::class);
        $hasService = $sale->items->contains(fn ($item): bool => $item->sellable_type !== Product::class);

        return DB::transaction(function () use ($sale, $actor, $split, $hasProduct, $hasService): Collection {
            $documents = collect();

            if ($hasProduct) {
                $documents->push($this->issueOne($sale, $this->productDocumentKind($sale), $split['product']));
            }

            if ($hasService) {
                $documents->push($this->issueOne($sale, FiscalDocumentKind::NFSE, $split['service']));
            }

            return $documents;
        });
    }

    public function cancel(FiscalDocument $document, string $reason): FiscalDocument
    {
        abort_unless($document->status->canBeCancelled(), 422, 'Só um documento autorizado pode ser cancelado.');

        $result = $this->gateway->cancel($document, $reason);
        $document->update(['status' => $result->status->value]);

        return $document->fresh();
    }

    private function issueOne(Sale $sale, FiscalDocumentKind $kind, float $total): FiscalDocument
    {
        $ownership = ['organization_id' => $sale->organization_id, 'professional_id' => $sale->professional_id];

        $document = FiscalDocument::create($ownership + [
            'sale_id' => $sale->id,
            'kind' => $kind->value,
            'status' => FiscalDocumentStatus::PENDING->value,
            'total' => $total,
        ]);

        $result = $this->gateway->issue($document);

        $document->update([
            'status' => $result->status->value,
            'access_key' => $result->accessKey,
            'provider_id' => $result->providerId,
            'rejection_reason' => $result->rejectionReason,
            'issued_at' => $result->status === FiscalDocumentStatus::AUTHORIZED ? now() : null,
        ]);

        return $document->fresh();
    }

    /** NFC-e para consumidor final presencial/domiciliar; NF-e para o resto — nunca NFC-e em revenda. */
    private function productDocumentKind(Sale $sale): FiscalDocumentKind
    {
        return FiscalDocumentKind::from($sale->fiscal_operation->productDocument());
    }

    private function assertNotAlreadyIssued(Sale $sale): void
    {
        $hasOpenDocument = FiscalDocument::query()
            ->where('sale_id', $sale->id)
            ->whereNotIn('status', [FiscalDocumentStatus::CANCELLED->value, FiscalDocumentStatus::DENIED->value])
            ->exists();

        abort_if($hasOpenDocument, 422, 'Esta venda já tem documento fiscal emitido ou em processamento.');
    }
}
