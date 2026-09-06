<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            // Core fields — appointment_date is stored as full datetime (see BookingService::createBooking)
            'appointment_date' => $this->appointment_date?->toISOString(),
            'appointment_time' => $this->appointment_date?->format('H:i'),
            'duration' => $this->duration,
            'type' => $this->type,
            'status' => $this->status,

            // Details
            'reason' => $this->reason,
            'notes' => $this->notes,
            'price' => $this->price ? (float) $this->price : null,
            'cancellation_reason' => $this->cancellation_reason,
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'confirmed_at' => $this->confirmed_at?->toISOString(),

            // Formatted helpers for the frontend
            'status_label' => $this->getStatusLabel(),
            'type_label' => $this->getTypeLabel(),

            // Relationships (only when loaded)
            'client' => new UserResource($this->whenLoaded('client')),
            'professional' => new UserResource($this->whenLoaded('professional')),
            'pet' => new PetResource($this->whenLoaded('pet')),

            // Nested medical data (when loaded)
            'medical_records' => $this->whenLoaded('medicalRecords'),
            'prescriptions' => $this->whenLoaded('prescriptions'),
            'vaccinations' => $this->whenLoaded('vaccinations'),

            // Timestamps
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    private function getStatusLabel(): string
    {
        return match ($this->status) {
            'pending' => 'Aguardando confirmacao',
            'scheduled' => 'Agendado',
            'confirmed' => 'Confirmado',
            'in_progress' => 'Em andamento',
            'completed' => 'Concluido',
            'cancelled' => 'Cancelado',
            'no_show' => 'Nao compareceu',
            default => ucfirst($this->status ?? ''),
        };
    }

    private function getTypeLabel(): string
    {
        return match ($this->type) {
            'consultation' => 'Consulta',
            'surgery' => 'Cirurgia',
            'vaccination' => 'Vacinacao',
            'exam' => 'Exame',
            'emergency' => 'Emergencia',
            'grooming' => 'Banho e Tosa',
            'checkup' => 'Check-up',
            default => ucfirst($this->type ?? ''),
        };
    }
}
