<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public, unauthenticated view of a pet card — what a stranger sees after
 * scanning the QR code on a lost pet's carteirinha.
 *
 * Deliberately minimal: only what helps reunite the pet with its tutor.
 * No medical record, no vaccination/exam data, no CPF and no address —
 * and the tutor's contact only appears while the pet is actually reported
 * lost, never as a standing way to reach a tutor through a QR code.
 *
 * Expects `pet.user` (name + phone only) eager-loaded when `is_lost` may be
 * true; the relation is never touched otherwise.
 */
class PublicPetCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var \App\Models\Pet $this */
        return [
            'public_id' => $this->public_id,
            'name' => $this->name,
            'species' => $this->species,
            'breed' => $this->breed,
            'photo_url' => $this->image_url,
            'is_lost' => $this->is_lost,
            'lost_alert_message' => $this->when($this->is_lost, $this->lost_alert_message),
            'contact' => $this->when($this->is_lost, fn () => [
                'name' => $this->user->name,
                'phone' => $this->user->phone,
            ]),
        ];
    }
}
