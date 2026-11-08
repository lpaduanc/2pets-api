<?php

namespace App\Services\Fiscal;

use App\Contracts\FiscalGatewayResolver;
use App\Enums\FiscalDocumentKind;
use App\Enums\FiscalDocumentOperation;
use App\Enums\FiscalDocumentStatus;
use App\Exceptions\Fiscal\FiscalProviderUnavailable;
use App\Exceptions\Fiscal\NfeXmlInvalid;
use App\Models\FiscalDocument;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\Commercial\Card\CardSurchargePolicy;
use App\Support\Money\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Emissão fiscal a partir de uma venda — contrato
 * docs/gap-simplesvet/05-emissao-fiscal-nfe-nfce-nfse-spec.md e docs/gap-petflow/specs/
 * fase4-fiscal-sem-envio.md.
 *
 * Roteamento: venda só de serviço emite NFS-e; só de produto emite NFC-e/NF-e
 * (`FiscalOperation::productDocument()`); venda mista emite as duas — nunca um documento só.
 * Revenda (`fiscal_operation.isResale()`) sempre NF-e, nunca NFC-e, mesmo presencial.
 *
 * Cada documento leva os itens congelados (`FiscalSaleLineBuilder`), o número reservado e o
 * despacho com tratamento de indisponibilidade (`FiscalDocumentIssuer`).
 */
final class FiscalDocumentService
{
    public function __construct(
        private readonly FiscalGatewayResolver $gateways,
        private readonly FiscalSaleLineBuilder $lines,
        private readonly FiscalDocumentIssuer $issuer,
        private readonly FiscalDocumentDispatcher $dispatcher,
    ) {}

    /**
     * Linha de brinde/cortesia/retorno (PLANO 1.4) fica FORA do documento; venda só com elas
     * devolve coleção vazia — nada a emitir, não é erro (`isCourtesyOnly`). A saída fiscal de
     * bonificação (CFOP 5.910) é pendência do contador: docs/PENDENCIAS.md.
     *
     * @return Collection<int, FiscalDocument>
     */
    public function issueFromSale(Sale $sale, User $actor): Collection
    {
        abort_if($sale->items->isEmpty(), 422, 'Venda sem item não emite documento fiscal.');

        if (self::isCourtesyOnly($sale)) {
            return collect();
        }

        $this->assertNotAlreadyIssued($sale);
        $sale->loadMissing(['items.sellable', 'client']);
        $parts = $this->documentParts($sale);

        // Venda mista: os dois documentos nascem juntos (ou nenhum) e só DEPOIS do commit vão ao
        // driver — nada autorizado na SEFAZ fica sem registro se o segundo falhar na validação.
        $documents = DB::transaction(fn (): Collection => collect($parts)->map(
            fn (array $part, string $type): FiscalDocument => $this->createOne($sale, $this->kindFor($sale, $type), $part)
        )->values());

        return $documents->map(fn (FiscalDocument $document): FiscalDocument => $this->dispatcher->send($document));
    }

    /**
     * Itens e acréscimo de cada documento, só dos tipos presentes na venda. O acréscimo é rateado
     * pelo maior resto entre produto e serviço sobre a base (líquido) de cada um: Σ totais dos
     * documentos = total da venda, centavo a centavo.
     *
     * @return array<'product'|'service', array{items: Collection<int, SaleItem>, surcharge: Money}>
     */
    private function documentParts(Sale $sale): array
    {
        $net = $sale->netItemTotals();
        $items = $sale->items->reject(fn (SaleItem $item): bool => $item->isCourtesy())
            ->groupBy(fn (SaleItem $item): string => $item->sellable_type === Product::class ? 'product' : 'service');
        $bases = $items->map(fn (Collection $group): Money => Money::sum($group->map(fn (SaleItem $item): Money => $net[$item->id])))->all();
        $surcharges = $this->surchargeShares($sale, $bases);

        return $items->map(fn (Collection $group, string $type): array => ['items' => $group->values(), 'surcharge' => $surcharges[$type]])->all();
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

    /** Venda em que toda linha é brinde/cortesia/retorno: nenhum documento a emitir. Exige `items`. */
    public static function isCourtesyOnly(Sale $sale): bool
    {
        return $sale->items->isNotEmpty() && $sale->items->every(fn ($item): bool => $item->isCourtesy());
    }

    /**
     * Cancelamento grava protocolo, motivo, data e o XML do evento. Contingência ainda não chegou
     * à SEFAZ: não há o que cancelar lá — transmite primeiro (spec fase4-fiscal §4). Recusa da
     * SEFAZ (ex.: prazo de cancelamento vencido) NÃO mexe no documento — ele continua autorizado
     * — e volta 422 com o motivo; SEFAZ fora do ar volta 503 (spec fase4-transmissao §7.3).
     */
    public function cancel(FiscalDocument $document, string $reason): FiscalDocument
    {
        abort_if($document->status === FiscalDocumentStatus::CONTINGENCY, 422, 'Documento em contingência: transmita antes de cancelar.');
        abort_unless($document->status->canBeCancelled(), 422, 'Só um documento autorizado pode ser cancelado.');

        try {
            $result = $this->gateways->forDocument($document)->cancel($document, $reason);
        } catch (FiscalProviderUnavailable $unavailable) {
            abort(503, 'SEFAZ indisponível para o cancelamento agora: '.$unavailable->getMessage());
        } catch (NfeXmlInvalid $invalid) {
            abort(422, $invalid->getMessage());
        }

        abort_unless($result->status === FiscalDocumentStatus::CANCELLED, 422, 'A SEFAZ recusou o cancelamento: '.$result->rejectionReason);

        $document->update([
            'status' => $result->status->value,
            'cancellation_protocol' => $result->protocol,
            'cancellation_reason' => $reason,
            'cancelled_at' => now(),
            'cancellation_xml_path' => $result->xmlPath,
        ]);

        return $document->fresh();
    }

    /**
     * O acréscimo vai somado ao total do documento: "outras despesas acessórias" (vOutro) na
     * NF-e/NFC-e, somado ao valor do serviço na NFS-e — o mapeamento por layout é do gateway.
     *
     * @param  array{items: Collection<int, SaleItem>, surcharge: Money}  $part
     */
    private function createOne(Sale $sale, FiscalDocumentKind $kind, array $part): FiscalDocument
    {
        return $this->issuer->create([
            'organization_id' => $sale->organization_id,
            'professional_id' => $sale->professional_id,
            'sale_id' => $sale->id,
            'operation' => FiscalDocumentOperation::SALE->value,
            'kind' => $kind->value,
            'surcharge_amount' => $part['surcharge']->toString(),
        ], $this->lines->build($sale, $kind, $part['items'], $part['surcharge']));
    }

    /** NFC-e para consumidor final presencial/domiciliar; NF-e para o resto — nunca NFC-e em revenda. */
    private function kindFor(Sale $sale, string $type): FiscalDocumentKind
    {
        return $type === 'service' ? FiscalDocumentKind::NFSE : FiscalDocumentKind::from($sale->fiscal_operation->productDocument());
    }

    /** Só documento DA VENDA ocupa a emissão (devolução tem documento próprio). */
    private function assertNotAlreadyIssued(Sale $sale): void
    {
        $hasOpenDocument = FiscalDocument::query()
            ->where('sale_id', $sale->id)
            ->operation(FiscalDocumentOperation::SALE)
            ->blockingReissue()
            ->exists();

        abort_if($hasOpenDocument, 422, 'Esta venda já tem documento fiscal emitido ou em processamento.');
    }
}
