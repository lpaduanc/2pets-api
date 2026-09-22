<?php

namespace App\Http\Resources\Fiscal;

use App\Models\FiscalDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrato docs/gap-simplesvet/05-emissao-fiscal-nfe-nfce-nfse-spec.md. Nunca expõe
 * `certificate_ref` (não é campo deste model) nem qualquer dado de credencial — só o
 * resultado público do documento emitido.
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
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'series' => $this->series,
            'number' => $this->number,
            'access_key' => $this->access_key,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'provider' => $this->provider,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'total' => (float) $this->total,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
