<?php

namespace App\Services\Fiscal;

use App\DataTransferObjects\Fiscal\FiscalInvoicePayload;
use App\DataTransferObjects\Fiscal\FiscalOwner;
use App\DataTransferObjects\Fiscal\FiscalParty;
use App\Enums\FiscalDocumentOperation;
use App\Enums\FiscalEmissionMode;
use App\Enums\FiscalEnvironment;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Support\Money\Money;

/**
 * Do documento persistido (com itens congelados) para o payload neutro que vai ao gateway — spec
 * docs/gap-petflow/specs/fase4-fiscal-sem-envio.md §5. Só lê: nada aqui recalcula imposto; os
 * totais são a soma dos itens (a mesma do `fiscal_documents.total`).
 */
final class FiscalInvoicePayloadBuilder
{
    /** Item → total do documento (grupo ICMSTot). */
    private const TOTAL_FIELDS = [
        'products' => 'gross_total',
        'discount' => 'discount_share',
        'other' => 'surcharge_share',
        'icms_base' => 'icms_base',
        'icms' => 'icms_amount',
        'fcp' => 'fcp_amount',
        'pis' => 'pis_amount',
        'cofins' => 'cofins_amount',
        'ipi' => 'ipi_amount',
        'icms_st' => 'icms_st_amount',
        'total' => 'total',
    ];

    /** `indPres` das devoluções: "não se aplica" (NF-e de devolução não é venda presencial). */
    private const PRESENCE_NOT_APPLICABLE = '0';

    public function __construct(
        private readonly FiscalPartyResolver $parties,
        private readonly FiscalPaymentAllocator $payments,
    ) {}

    public function build(FiscalDocument $document): FiscalInvoicePayload
    {
        $document->loadMissing('items');

        return new FiscalInvoicePayload(
            documentId: $document->id,
            kind: $document->kind,
            operation: $document->operation ?? FiscalDocumentOperation::SALE,
            direction: $document->direction ?? ($document->operation ?? FiscalDocumentOperation::SALE)->direction(),
            environment: $document->environment ?? FiscalEnvironment::HOMOLOGATION,
            emissionMode: $document->emission_mode ?? FiscalEmissionMode::NORMAL,
            contingencyReason: $document->contingency_reason,
            series: $document->series,
            number: $document->number,
            emitter: $this->parties->emitter(FiscalOwner::of($document)),
            recipient: $this->recipient($document),
            items: $document->items->map(fn (FiscalDocumentItem $item): array => $this->item($item))->values()->all(),
            totals: $this->totals($document),
            references: array_values(array_filter([$document->referenced_access_key])),
            payments: $this->paymentsFor($document),
            presence: $this->presenceFor($document),
            finalConsumer: $this->isFinalConsumer($document),
        );
    }

    /**
     * Só a venda tem pagamento; devolução sai "sem pagamento" (tPag 90).
     *
     * @return list<array{method: string|null, amount: string}>
     */
    private function paymentsFor(FiscalDocument $document): array
    {
        if (($document->operation ?? FiscalDocumentOperation::SALE) !== FiscalDocumentOperation::SALE) {
            return [];
        }

        return $this->payments->forSale($document->sale, (string) $document->total);
    }

    private function presenceFor(FiscalDocument $document): string
    {
        if (($document->operation ?? FiscalDocumentOperation::SALE) !== FiscalDocumentOperation::SALE) {
            return self::PRESENCE_NOT_APPLICABLE;
        }

        return $document->sale?->fiscal_operation?->presenceIndicator() ?? self::PRESENCE_NOT_APPLICABLE;
    }

    /** Revenda e devolução ao fornecedor não são consumidor final (`indFinal = 0`). */
    private function isFinalConsumer(FiscalDocument $document): bool
    {
        if ($document->purchase_return_id !== null) {
            return false;
        }

        $sale = $document->sale ?? $document->saleReturn?->sale;

        return ! ($sale?->fiscal_operation?->isResale() ?? false);
    }

    private function recipient(FiscalDocument $document): ?FiscalParty
    {
        if ($document->purchase_return_id !== null) {
            return $this->parties->supplier($document->purchaseReturn->purchase->supplier);
        }

        return $this->parties->client($document->sale?->client);
    }

    /** @return array<string, mixed> */
    private function item(FiscalDocumentItem $item): array
    {
        return $item->only([
            'line_number', 'product_id', 'description', 'ncm', 'cest', 'unit', 'quantity', 'unit_price',
            'gross_total', 'discount_share', 'surcharge_share', 'total', 'cfop', 'origin', 'csosn', 'cst',
            'icms_base', 'icms_rate', 'icms_amount', 'icms_base_reduction_percent', 'icms_sn_credit_rate',
            'icms_sn_credit_amount', 'fcp_rate', 'fcp_amount', 'pis_cst', 'pis_base', 'pis_rate', 'pis_amount',
            'cofins_cst', 'cofins_base', 'cofins_rate', 'cofins_amount', 'ipi_amount', 'icms_st_amount', 'benefit_code',
            // NFS-e nacional: códigos do serviço da DPS (spec fase4-transmissao-propria-nfse-nacional §5.3).
            'national_tax_code', 'municipal_tax_code', 'nbs_code',
        ]);
    }

    /** @return array<string, string> */
    private function totals(FiscalDocument $document): array
    {
        return array_map(
            fn (string $field): string => Money::sum($document->items->map(fn (FiscalDocumentItem $item): Money => Money::of($item->{$field})))->toString(),
            self::TOTAL_FIELDS,
        );
    }
}
