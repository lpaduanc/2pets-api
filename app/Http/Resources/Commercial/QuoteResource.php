<?php

namespace App\Http\Resources\Commercial;

use App\Enums\QuoteStatus;
use App\Models\Sale;
use Illuminate\Http\Request;

/**
 * Orçamento na visão da CLÍNICA — docs/gap-simplesvet/24-orcamentos.md.
 *
 * Estende `SaleResource` em vez de repetir itens/totais/cliente: orçamento é venda com
 * `kind = quote` (decisão do doc 24), e a tela do orçamento lê os mesmos campos do PDV. Aqui
 * entram só o ciclo de aprovação, a versão e a origem clínica.
 *
 * `versions` só aparece quando o controller injeta a família (`show`) — no `index` seria uma
 * query por linha.
 *
 * @mixin Sale
 */
class QuoteResource extends SaleResource
{
    /** Relações que o resource lê — eager load obrigatório nos controllers. */
    public const RELATIONS = [
        ...Sale::RESOURCE_RELATIONS,
        'decidedBy',
        'convertedToSale',
        'medicalRecord:id,record_date,chief_complaint',
        'hospitalization:id,admission_date,status',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->effectiveQuoteStatus();

        return [
            ...parent::toArray($request),
            ...self::quoteFields($this->resource),

            'can_send' => in_array($status, [QuoteStatus::DRAFT, QuoteStatus::SENT, QuoteStatus::VIEWED], true)
                && $this->client_id !== null,
            'can_revise' => ! in_array($this->quote_status, [QuoteStatus::CONVERTED, QuoteStatus::SUPERSEDED], true),
            'can_convert' => in_array($status, [QuoteStatus::APPROVED, QuoteStatus::DRAFT, QuoteStatus::SENT, QuoteStatus::VIEWED], true),

            'decided_by' => $this->whenLoaded('decidedBy', fn () => $this->decidedBy ? [
                'id' => $this->decidedBy->id,
                'name' => $this->decidedBy->name,
            ] : null),
            'converted_sale' => $this->whenLoaded('convertedToSale', fn () => $this->convertedToSale ? [
                'id' => $this->convertedToSale->id,
                'number' => $this->convertedToSale->number,
                'status' => $this->convertedToSale->status->value,
                'status_label' => $this->convertedToSale->status->label(),
            ] : null),
            'origin' => [
                'medical_record_id' => $this->medical_record_id,
                'medical_record' => $this->whenLoaded('medicalRecord', fn () => $this->medicalRecord ? [
                    'id' => $this->medicalRecord->id,
                    'record_date' => $this->medicalRecord->record_date?->toDateString(),
                    'chief_complaint' => $this->medicalRecord->chief_complaint,
                ] : null),
                'hospitalization_id' => $this->hospitalization_id,
                'hospitalization' => $this->whenLoaded('hospitalization', fn () => $this->hospitalization ? [
                    'id' => $this->hospitalization->id,
                    'admission_date' => $this->hospitalization->admission_date?->toDateString(),
                    'status' => $this->hospitalization->status instanceof \BackedEnum
                        ? $this->hospitalization->status->value
                        : $this->hospitalization->status,
                ] : null),
            ],
            'versions' => $this->when(
                $this->resource->relationLoaded('familyVersions'),
                fn () => $this->resource->getRelation('familyVersions')->map(fn (Sale $version) => [
                    'id' => $version->id,
                    'version' => $version->version,
                    'quote_status' => $version->effectiveQuoteStatus()?->value,
                    'quote_status_label' => $version->effectiveQuoteStatus()?->label(),
                    'total' => (float) $version->total,
                    'created_at' => $version->created_at?->toIso8601String(),
                ])->values()
            ),
        ];
    }

    /**
     * Campos do ciclo do orçamento comuns à visão da clínica e à do tutor.
     *
     * @return array<string, mixed>
     */
    public static function quoteFields(Sale $quote): array
    {
        $status = $quote->effectiveQuoteStatus();

        return [
            'quote_status' => $status?->value,
            'quote_status_label' => $status?->label(),
            'is_expired' => $status === QuoteStatus::EXPIRED,
            'version' => $quote->version,
            'parent_quote_id' => $quote->parent_quote_id,
            'root_quote_id' => $quote->rootQuoteId(),
            'sent_at' => $quote->sent_at?->toIso8601String(),
            'viewed_at' => $quote->viewed_at?->toIso8601String(),
            'decided_at' => $quote->decided_at?->toIso8601String(),
            'decision_channel' => $quote->decision_channel,
            'rejection_reason' => $quote->rejection_reason,
            'has_pdf' => $quote->pdf_path !== null,
        ];
    }
}
