<?php

namespace App\Http\Resources\Fiscal;

use App\Models\FiscalDocument;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrato docs/gap-simplesvet/05-emissao-fiscal-nfe-nfce-nfse-spec.md e docs/gap-petflow/specs/
 * fase4-fiscal-sem-envio.md §6. Nunca expõe `certificate_ref` (não é campo deste model) nem
 * credencial — só o resultado público do documento. `items` e `referenced_document` só no
 * detalhe (carregados pelo controller), para a listagem não cair em N+1.
 *
 * @mixin FiscalDocument
 */
class FiscalDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sale_id' => $this->sale_id,
            // Número que a pessoa vê em Vendas (#13), não o id interno — a lista mostrava "Venda #46".
            'sale_number' => $this->whenLoaded('sale', fn () => $this->sale?->number),
            'operation' => $this->operation?->value,
            'direction' => $this->direction?->value,
            'sale_return_id' => $this->sale_return_id,
            'purchase_return_id' => $this->purchase_return_id,
            'referenced_document_id' => $this->referenced_document_id,
            'referenced_access_key' => $this->referenced_access_key,
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'environment' => $this->environment?->value,
            'emission_mode' => $this->emission_mode?->value,
            'contingency_reason' => $this->contingency_reason,
            'is_contingency_overdue' => $this->isContingencyOverdue(),
            'series' => $this->series,
            'number' => $this->number,
            // NFS-e nacional: série/número da DPS (nossos); `number` é o nNFSe da Sefin.
            'dps_series' => $this->dps_series,
            'dps_number' => $this->dps_number,
            'access_key' => $this->access_key,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'provider' => $this->provider,
            'protocol' => $this->protocol,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'cancellation_protocol' => $this->cancellation_protocol,
            'cancellation_reason' => $this->cancellation_reason,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'attempts' => (int) $this->attempts,
            'next_retry_at' => $this->next_retry_at?->toIso8601String(),
            'last_error' => $this->last_error,
            'total' => (float) $this->total,
            // Parte do acréscimo de repasse da taxa contida no `total` (PLANO 1.1); string de 2
            // casas, como os demais campos de acréscimo.
            'surcharge_amount' => Money::of($this->surcharge_amount ?? '0')->toString(),
            // Só se há arquivo — o caminho no disco privado nunca sai; o download é por
            // `GET fiscal-documents/{id}/xml|pdf` (spec fase4-transmissao §8).
            'has_xml' => $this->xml_path !== null,
            'has_pdf' => $this->pdf_path !== null,
            'items' => FiscalDocumentItemResource::collection($this->whenLoaded('items')),
            'referenced_document' => $this->whenLoaded('referencedDocument', fn (): ?array => $this->referencedDocument === null ? null : [
                'id' => $this->referencedDocument->id,
                'kind' => $this->referencedDocument->kind->value,
                'series' => $this->referencedDocument->series,
                'number' => $this->referencedDocument->number,
                'access_key' => $this->referencedDocument->access_key,
                'status' => $this->referencedDocument->status->value,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
