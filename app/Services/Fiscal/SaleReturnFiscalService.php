<?php

namespace App\Services\Fiscal;

use App\DataTransferObjects\Fiscal\FiscalOwner;
use App\DataTransferObjects\Fiscal\FiscalParty;
use App\DataTransferObjects\Fiscal\FiscalTaxRule;
use App\Enums\FiscalCfopOperation;
use App\Enums\FiscalCfopScope;
use App\Enums\FiscalDocumentKind;
use App\Enums\FiscalDocumentOperation;
use App\Enums\FiscalDocumentStatus;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Models\Product;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Services\Commercial\Card\CardSurchargePolicy;
use App\Services\Fiscal\Calculation\FiscalCfopSelector;
use App\Services\Fiscal\Calculation\FiscalReturnLineMirror;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Illuminate\Support\Collection;

/**
 * NF-e de ENTRADA da devolução de venda — spec docs/gap-petflow/specs/fase4-fiscal-sem-envio.md
 * §3.5. Sempre modelo 55 (mesmo quando a venda saiu em NFC-e), referenciando a chave do documento
 * de produto autorizado da venda; só produtos (serviço não tem devolução fiscal).
 */
final class SaleReturnFiscalService
{
    public function __construct(
        private readonly FiscalPartyResolver $parties,
        private readonly FiscalRuleResolver $rules,
        private readonly FiscalCfopSelector $cfops,
        private readonly FiscalReturnLineMirror $mirror,
        private readonly FiscalDocumentIssuer $issuer,
    ) {}

    /** `null` = devolução só de serviço: nada a emitir (o controller responde `service_only`). */
    public function issue(SaleReturn $return): ?FiscalDocument
    {
        $return->loadMissing(['items.saleItem.sellable', 'sale.client']);
        $lines = $return->items->filter(fn (SaleReturnItem $line): bool => $line->saleItem?->sellable_type === Product::class)->values();

        if ($lines->isEmpty()) {
            return null;
        }

        $this->assertNotAlreadyIssued($return);
        $original = $this->authorizedProductDocument($return);
        $recipient = $this->identifiedClient($return);
        $rows = $this->rows($return, $lines, $original, $this->scope($return, $recipient));

        return $this->issuer->issue($this->documentAttributes($return, $original, $rows), $rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function documentAttributes(SaleReturn $return, FiscalDocument $original, array $rows): array
    {
        return [
            'organization_id' => $return->organization_id,
            'professional_id' => $return->professional_id,
            'sale_id' => $return->sale_id,
            'sale_return_id' => $return->id,
            'operation' => FiscalDocumentOperation::SALE_RETURN->value,
            'kind' => FiscalDocumentKind::NFE->value,
            'referenced_document_id' => $original->id,
            'referenced_access_key' => $original->access_key,
            'surcharge_amount' => Money::sum(array_map(fn (array $row): Money => Money::of($row['surcharge_share']), $rows))->toString(),
        ];
    }

    /**
     * @param  Collection<int, SaleReturnItem>  $lines
     * @return list<array<string, mixed>>
     */
    private function rows(SaleReturn $return, Collection $lines, FiscalDocument $original, FiscalCfopScope $scope): array
    {
        $problems = new FiscalIssueProblems;
        $refundSurcharge = CardSurchargePolicy::forSale($return->sale)->refundsProportionally();
        $rows = $lines->map(fn (SaleReturnItem $line, int $index): ?array => $this->row($line, $index + 1, $original, [$scope, $refundSurcharge, $problems]))->all();
        $problems->throwIfAny();

        return array_values($rows);
    }

    /**
     * @param  array{0: FiscalCfopScope, 1: bool, 2: FiscalIssueProblems}  $context
     * @return array<string, mixed>|null
     */
    private function row(SaleReturnItem $line, int $lineNumber, FiscalDocument $original, array $context): ?array
    {
        [$scope, $refundSurcharge, $problems] = $context;
        $originalItem = $original->items->firstWhere('sale_item_id', $line->sale_item_id);

        if ($originalItem === null) {
            $problems->add(sprintf('"%s" não está detalhado no documento original (emitido antes do perfil tributário): emita a devolução manualmente.', $line->saleItem->description));

            return null;
        }

        $cfop = $this->rules->cfopFor($originalItem->taxProfile, $originalItem->description, [FiscalCfopOperation::RETURN_FROM_CUSTOMER, $scope], $problems);
        $rule = FiscalTaxRule::fromSnapshot($originalItem, $cfop);
        $slice = ['before' => $this->returnedBefore($line), 'quantity' => Quantity::of($line->quantity)];

        return $this->snapshot($originalItem, $line, $lineNumber)
            + $this->mirror->mirror($originalItem->only([...FiscalDocumentItem::MONEY_FIELDS, 'quantity']), $rule, $slice, $refundSurcharge)
            + $rule->toSnapshot();
    }

    /** @return array<string, mixed> */
    private function snapshot(FiscalDocumentItem $originalItem, SaleReturnItem $line, int $lineNumber): array
    {
        return [
            'line_number' => $lineNumber,
            'sale_item_id' => $line->sale_item_id,
            'sale_return_item_id' => $line->id,
            'product_id' => $originalItem->product_id,
            'description' => $originalItem->description,
            'ncm' => $originalItem->ncm,
            'cest' => $originalItem->cest,
            'unit' => $originalItem->unit,
            'quantity' => (string) $line->quantity,
            'unit_price' => (string) $originalItem->unit_price,
        ];
    }

    /** O que desta linha da venda já voltou em devoluções ANTERIORES a esta. */
    private function returnedBefore(SaleReturnItem $line): Quantity
    {
        return Quantity::of(SaleReturnItem::query()
            ->where('sale_item_id', $line->sale_item_id)
            ->where('sale_return_id', '<', $line->sale_return_id)
            ->sum('quantity'));
    }

    private function authorizedProductDocument(SaleReturn $return): FiscalDocument
    {
        $original = FiscalDocument::query()
            ->with('items.taxProfile.cfops')
            ->where('sale_id', $return->sale_id)
            ->operation(FiscalDocumentOperation::SALE)
            ->whereIn('kind', [FiscalDocumentKind::NFE->value, FiscalDocumentKind::NFCE->value])
            ->where('status', FiscalDocumentStatus::AUTHORIZED->value)
            ->latest('id')
            ->first();

        abort_if($original === null, 422, 'A venda não tem NFC-e/NF-e de produto autorizada: emita (ou transmita) o documento da venda antes da devolução.');

        return $original;
    }

    private function identifiedClient(SaleReturn $return): FiscalParty
    {
        $client = $this->parties->client($return->sale->client);

        abort_if($client === null, 422, 'Venda sem cliente identificado: informe o cliente com CPF e endereço para emitir a NF-e de devolução.');

        $missing = $client->missingForNfe();
        abort_if($missing !== [], 422, 'Complete o cadastro do cliente para a NF-e de devolução: '.implode(', ', $missing).'.');

        return $client;
    }

    private function scope(SaleReturn $return, FiscalParty $recipient): FiscalCfopScope
    {
        $emitter = $this->parties->emitter(FiscalOwner::of($return));

        return $this->cfops->scope($emitter->state(), $recipient->state(), FiscalDocumentKind::NFE);
    }

    private function assertNotAlreadyIssued(SaleReturn $return): void
    {
        $exists = FiscalDocument::query()->where('sale_return_id', $return->id)->blockingReissue()->exists();

        abort_if($exists, 422, 'Esta devolução já tem NF-e emitida ou em processamento.');
    }
}
