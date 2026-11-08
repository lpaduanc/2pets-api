<?php

namespace App\Services\Fiscal;

use App\DataTransferObjects\Fiscal\FiscalOwner;
use App\DataTransferObjects\Fiscal\FiscalParty;
use App\DataTransferObjects\Fiscal\FiscalRuleContext;
use App\DataTransferObjects\Fiscal\FiscalTaxRule;
use App\Enums\FiscalCfopOperation;
use App\Enums\FiscalDocumentKind;
use App\Enums\FiscalDocumentOperation;
use App\Enums\TaxRegime;
use App\Models\FiscalDocument;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Services\Fiscal\Calculation\FiscalCfopSelector;
use App\Services\Fiscal\Calculation\FiscalTaxCalculator;
use App\Support\Fiscal\FiscalAccessKey;
use App\Support\Money\Money;

/**
 * NF-e de SAÍDA da devolução de compra ao fornecedor — spec docs/gap-petflow/specs/
 * fase4-fiscal-sem-envio.md §3.6. Referencia a chave da nota de entrada (`purchases.invoice_key`);
 * IPI e ICMS-ST vêm congelados na linha da devolução (rateio acumulado). O ICMS próprio é
 * recalculado pelo perfil atual do produto — a compra não grava o ICMS de entrada por item (item
 * aberto da spec).
 */
final class PurchaseReturnFiscalService
{
    public function __construct(
        private readonly FiscalPartyResolver $parties,
        private readonly FiscalRuleResolver $rules,
        private readonly FiscalCfopSelector $cfops,
        private readonly FiscalTaxCalculator $calculator,
        private readonly FiscalDocumentIssuer $issuer,
    ) {}

    public function issue(PurchaseReturn $return): FiscalDocument
    {
        $return->loadMissing(['items.purchaseItem.product', 'purchase.supplier']);
        $this->assertNotAlreadyIssued($return);
        $invoiceKey = $this->invoiceKey($return);
        $rules = $this->rulesFor($return, $this->supplier($return));

        return $this->issuer->issue([
            'organization_id' => $return->organization_id,
            'professional_id' => $return->professional_id,
            'purchase_return_id' => $return->id,
            'operation' => FiscalDocumentOperation::PURCHASE_RETURN->value,
            'kind' => FiscalDocumentKind::NFE->value,
            'referenced_access_key' => $invoiceKey,
        ], $return->items->values()->map(fn (PurchaseReturnItem $line, int $index): array => $this->row($line, $index + 1, $rules[$line->id]))->all());
    }

    /** @return array<int, FiscalTaxRule|null> por `purchase_return_items.id` */
    private function rulesFor(PurchaseReturn $return, FiscalParty $supplier): array
    {
        $problems = new FiscalIssueProblems;
        $owner = FiscalOwner::of($return);
        $emitter = $this->parties->emitter($owner);
        $scope = $this->cfops->scope($emitter->state(), $supplier->state(), FiscalDocumentKind::NFE);
        $context = new FiscalRuleContext($owner, TaxRegime::tryFrom((string) $emitter->taxRegime), FiscalCfopOperation::RETURN_TO_SUPPLIER, $scope);

        $return->items->each(fn (PurchaseReturnItem $line) => self::assertNcm($line, $problems));
        $rules = $this->rules->resolve($context, $return->items->mapWithKeys(fn (PurchaseReturnItem $line): array => [$line->id => ['product_id' => (int) $line->purchaseItem->product_id, 'description' => self::description($line)]])->all(), $problems);
        $problems->throwIfAny();

        return $rules;
    }

    /** @return array<string, mixed> */
    private function row(PurchaseReturnItem $line, int $lineNumber, ?FiscalTaxRule $rule): array
    {
        $item = $line->purchaseItem;
        $net = Money::of($line->products_total)->minus(Money::of($line->discount_share));

        return [
            'line_number' => $lineNumber,
            'purchase_return_item_id' => $line->id,
            'product_id' => $item->product_id,
            'description' => self::description($line),
            'ncm' => self::ncmOf($line),
            'cest' => $item->product?->cest,
            'unit' => mb_substr((string) ($item->unit ?: 'UN'), 0, 10),
            'quantity' => (string) $line->quantity,
            'unit_price' => (string) $line->unit_cost,
            'gross_total' => (string) $line->products_total,
            'discount_share' => (string) $line->discount_share,
            'surcharge_share' => '0.00',
            'ipi_amount' => (string) $line->ipi_share,
            'icms_st_amount' => (string) $line->icms_st_share,
            'total' => (string) $line->total,
        ] + $this->calculator->taxes($net, $rule) + ($rule?->toSnapshot() ?? []);
    }

    private function invoiceKey(PurchaseReturn $return): string
    {
        $key = preg_replace('/\D/', '', (string) $return->purchase->invoice_key);

        abort_unless(strlen($key) === FiscalAccessKey::LENGTH, 422, 'Informe a chave de acesso (44 dígitos) da nota de compra para emitir a NF-e de devolução.');

        return $key;
    }

    private function supplier(PurchaseReturn $return): FiscalParty
    {
        $supplier = $this->parties->supplier($return->purchase->supplier);
        $missing = array_values(array_intersect($supplier->missingForNfe(), ['CPF/CNPJ', 'UF']));

        abort_if($missing !== [], 422, 'Complete o cadastro do fornecedor para a NF-e de devolução: '.implode(', ', $missing).'.');

        return $supplier;
    }

    private function assertNotAlreadyIssued(PurchaseReturn $return): void
    {
        $exists = FiscalDocument::query()->where('purchase_return_id', $return->id)->blockingReissue()->exists();

        abort_if($exists, 422, 'Esta devolução de compra já tem NF-e emitida ou em processamento.');
    }

    private static function assertNcm(PurchaseReturnItem $line, FiscalIssueProblems $problems): void
    {
        if (self::ncmOf($line) === null) {
            $problems->add(sprintf('"%s" sem NCM no cadastro do produto.', self::description($line)));
        }
    }

    private static function ncmOf(PurchaseReturnItem $line): ?string
    {
        return $line->purchaseItem->product?->ncm ?? $line->purchaseItem->ncm;
    }

    private static function description(PurchaseReturnItem $line): string
    {
        return (string) ($line->purchaseItem->product?->name ?? $line->purchaseItem->description_on_invoice);
    }
}
