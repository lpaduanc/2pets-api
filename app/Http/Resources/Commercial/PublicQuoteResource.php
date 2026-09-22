<?php

namespace App\Http\Resources\Commercial;

use App\Models\Sale;
use App\Services\Commercial\QuoteIssuer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Orçamento no link SEM LOGIN — docs/gap-simplesvet/24-orcamentos.md, "Segurança".
 *
 * Lista branca estrita: itens, valores, validade, clínica, nome e espécie do animal e o
 * primeiro nome do tutor. NUNCA diagnóstico, prontuário, id do atendimento, telefone ou
 * sobrenome do tutor — quem tem o link pode não ser o tutor (encaminhou para a família).
 *
 * @mixin Sale
 */
class PublicQuoteResource extends JsonResource
{
    public const RELATIONS = ['items', 'pet', 'client', 'organization', 'professional.professional'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->effectiveQuoteStatus();
        $issuer = QuoteIssuer::for($this->resource);

        return [
            'number' => $this->number,
            'version' => $this->version,
            'quote_status' => $status?->value,
            'quote_status_label' => $status?->label(),
            'is_expired' => $status === \App\Enums\QuoteStatus::EXPIRED,
            'can_decide' => $status?->isAwaitingDecision() === true && $this->public_token_used_at === null,
            'valid_until' => $this->valid_until?->toDateString(),
            'clinic' => [
                'name' => $issuer['name'],
                'document' => $issuer['document'],
                'address' => $issuer['address'],
                'city' => $issuer['city'],
                'state' => $issuer['state'],
            ],
            'pet' => $this->pet ? ['name' => $this->pet->name, 'species' => $this->pet->species] : null,
            'client' => $this->client ? ['first_name' => strtok((string) $this->client->name, ' ') ?: null] : null,
            ...TutorQuoteResource::amounts($this->resource),
            'printed_notes' => $this->printed_notes,
        ];
    }
}
