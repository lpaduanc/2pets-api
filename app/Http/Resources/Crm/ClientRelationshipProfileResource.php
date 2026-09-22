<?php

namespace App\Http\Resources\Crm;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ClientRelationshipProfile
 *
 * Nunca inclui CPF/documento do cliente — só o essencial para a lista/ficha de segmentação
 * (nome, e-mail, telefone), mesmo cuidado de exposição de dado sensível do resto do projeto.
 */
class ClientRelationshipProfileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client' => [
                'id' => $this->client->id,
                'name' => $this->client->name,
                'email' => $this->client->email,
                'phone' => $this->client->phone,
            ],
            'client_origin' => $this->whenLoaded('clientOrigin', fn () => $this->clientOrigin?->name),
            // Ficha do cliente (achado de revisão do front de 18/19): tags atribuídas, só
            // quando `client.tags` foi eager-loaded — nunca dispara N+1 aqui.
            'tags' => $this->client->relationLoaded('tags')
                ? $this->client->tags->map(fn ($tag): array => ['id' => $tag->id, 'name' => $tag->name])->values()
                : [],
            'lifecycle_stage' => $this->lifecycle_stage?->value,
            'lifecycle_stage_label' => $this->lifecycle_stage?->label(),
            'abc_class' => $this->abc_class?->value,
            'abc_position' => $this->abc_position,
            'total_spent_365d' => (float) $this->total_spent_365d,
            'total_spent_90d' => (float) $this->total_spent_90d,
            'total_spent_30d' => (float) $this->total_spent_30d,
            'first_interaction_at' => $this->first_interaction_at?->toISOString(),
            'last_interaction_at' => $this->last_interaction_at?->toISOString(),
            'archived_at' => $this->archived_at?->toISOString(),
            'recalculated_at' => $this->recalculated_at?->toISOString(),
        ];
    }
}
