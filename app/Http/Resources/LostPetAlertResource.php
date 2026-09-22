<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Um alerta de pet perdido como o app o mostra no menu "Pets Perdidos".
 *
 * Serve os dois lados da lista com o mesmo formato: o alerta do proprio tutor
 * (que tem o botao "encontrei") e o alerta de um vizinho (que so tem o contato).
 * O telefone do tutor so sai daqui enquanto o alerta esta `active` — mesma regra
 * do {@see PublicPetCardResource}: a carteirinha nao vira uma agenda de
 * telefones de tutores.
 *
 * Espera `pet` e `user` eager-loaded e, na lista "perto de mim", o
 * `distance_km` calculado pela query.
 */
class LostPetAlertResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var \App\Models\LostPetAlert $this */
        return [
            'id' => $this->id,
            'status' => $this->status,
            'pet' => [
                'id' => $this->pet?->id,
                'public_id' => $this->pet?->public_id,
                'name' => $this->pet?->name,
                'species' => $this->pet?->species,
                'breed' => $this->pet?->breed,
                'photo_url' => $this->pet?->image_url,
            ],
            'description' => $this->description,
            'last_seen_location' => $this->last_seen_location,
            'last_seen_latitude' => $this->last_seen_latitude !== null ? (float) $this->last_seen_latitude : null,
            'last_seen_longitude' => $this->last_seen_longitude !== null ? (float) $this->last_seen_longitude : null,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'alert_radius_km' => (float) $this->alert_radius_km,
            'reward_amount' => $this->reward_amount !== null ? (float) $this->reward_amount : null,
            // Presente so na lista "perto de mim" (a query do proprio tutor nao
            // calcula distancia — ele sabe onde o pet sumiu).
            'distance_km' => $this->when(
                isset($this->distance_km),
                fn () => round((float) $this->distance_km, 1)
            ),
            'is_mine' => $this->user_id === $request->user()?->id,
            'contact' => [
                'name' => $this->user?->name,
                'phone' => $this->contact_info['phone'] ?? $this->user?->phone,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
