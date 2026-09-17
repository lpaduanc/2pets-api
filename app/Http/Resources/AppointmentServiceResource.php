<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Um item da pivô `appointment_services` — contrato §13.2/§13.7. */
class AppointmentServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'service_id' => $this->service_id,
            'service_name' => $this->whenLoaded('service', fn () => $this->service?->name),
            'quantity' => (float) $this->quantity,
            'unit_price' => (float) $this->unit_price,
            'total' => round((float) $this->quantity * (float) $this->unit_price, 2),
        ];
    }
}
