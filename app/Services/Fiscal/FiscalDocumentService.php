<?php

namespace App\Services\Fiscal;

use App\Contracts\FiscalProviderGateway;
use App\Enums\FiscalDocumentKind;
use App\Enums\FiscalDocumentStatus;
use App\Models\FiscalDocument;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\Commercial\Card\CardSurchargePolicy;
use App\Support\Money\Money;
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
    ) {}

    /**
     * @return Collection<int, FiscalDocument>
     */
    public function issueFromSale(Sale $sale, User $actor): Collection
    {
        abort_if($sale->items->isEmpty(), 422, 'Venda sem item não emite documento fiscal.');
        $this->assertNotAlreadyIssued($sale);

        $parts = $this->documentParts($sale);

        return DB::transaction(function () use ($sale, $parts): Collection {
            $documents = collect();

            if (isset($parts['product'])) {
                $documents->push($this->issueOne($sale, $this->productDocumentKind($sale), $parts['product']));
            }

            if (isset($parts['service'])) {
                $documents->push($this->issueOne($sale, FiscalDocumentKind::NFSE, $parts['service']));
            }

            return $documents;
        });
    }

    /**
     * Base (itens já com o desconto do cabeçalho, `Sale::netItemTotals`) e acréscimo de cada
     * documento, só dos tipos presentes na venda. Tudo em `Money` com rateio pelo maior resto:
     * Σ bases = subtotal − desconto e Σ acréscimos = acréscimo da venda, então Σ totais dos
     * documentos = total da venda, centavo a centavo (o antigo fator float podia errar 1 centavo).
     *
     * @return array<'product'|'service', array{base: Money, surcharge: Money}>
     */
    private function documentParts(Sale $sale): array
    {
        $bases = [];

        foreach ($sale->netItemTotals() as $itemId => $net) {
            $type = $sale->items->firstWhere('id', $itemId)->sellable_type === Product::class ? 'product' : 'service';
            $bases[$type] = ($bases[$type] ?? Money::zero())->plus($net);
        }

        $surcharges = $this->surchargeShares($sale, $bases);

        return array_map(
            fn (string $type): array => ['base' => $bases[$type], 'surcharge' => $surcharges[$type]],
            array_combine(array_keys($bases), array_keys($bases)),
        );
    }

    /**
     * Configuração lida AGORA, na emissão (`CardSurchargePolicy`). Desligada, o acréscimo fica
     * fora de todo documento (zero) — comportamento anterior ao repasse.
     *
     * @param  array<'product'|'service', Money>  $bases
     * @return array<'product'|'service', Money>
     */
    private function surchargeShares(Sale $sale, array $bases): array
    {
        $surcharge = Money::of($sale->surcharge_amount ?? '0');

        if ($surcharge->isZero() || ! CardSurchargePolicy::forSale($sale)->onInvoice) {
            return array_map(fn (): Money => Money::zero(), $bases);
        }

        return $surcharge->allocate($bases);
    }

    public function cancel(FiscalDocument $document, string $reason): FiscalDocument
    {
        abort_unless($document->status->canBeCancelled(), 422, 'Só um documento autorizado pode ser cancelado.');

        $result = $this->gateway->cancel($document, $reason);
        $document->update(['status' => $result->status->value]);

        return $document->fresh();
    }

    /**
     * O acréscimo vai somado ao total do documento: "outras despesas acessórias" (vOutro) na
     * NF-e/NFC-e, somado ao valor do serviço na NFS-e — o mapeamento por layout é do gateway.
     *
     * @param  array{base: Money, surcharge: Money}  $part
     */
    private function issueOne(Sale $sale, FiscalDocumentKind $kind, array $part): FiscalDocument
    {
        $ownership = ['organization_id' => $sale->organization_id, 'professional_id' => $sale->professional_id];

        $document = FiscalDocument::create($ownership + [
            'sale_id' => $sale->id,
            'kind' => $kind->value,
            'status' => FiscalDocumentStatus::PENDING->value,
            'total' => $part['base']->plus($part['surcharge'])->toString(),
            'surcharge_amount' => $part['surcharge']->toString(),
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
