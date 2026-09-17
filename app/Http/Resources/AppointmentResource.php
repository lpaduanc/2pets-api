<?php

namespace App\Http\Resources;

use App\Enums\AppointmentStatus;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            // Core fields — appointment_date holds only the calendar date (time is always 00:00:00);
            // the real time of day lives in the separate `appointment_time` column (see BookingService::createBooking)
            'appointment_date' => $this->appointment_date?->toISOString(),
            'appointment_time' => $this->appointment_time?->format('H:i'),
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

            // Contrato docs/atendimento-veterinario/09-faturamento-do-atendimento.md §13.2/
            // §13.7: serviços contratados (consulta + vacina + banho...). `price` acima já é
            // o total estimado — soma desta lista quando ela é usada.
            'services' => AppointmentServiceResource::collection($this->whenLoaded('services')),

            // Fatura `pending` criada automaticamente pelo `start()` (contrato §13.5) — vale
            // para QUALQUER tipo de agendamento, inclusive `grooming` (não tem MedicalRecord,
            // mas tem fatura igual). `null` até haver serviço/cobrança lançada (invariante 11).
            'invoice_id' => $this->whenLoaded('invoice', fn (?Invoice $invoice): ?int => $invoice?->id, null),

            // Nested medical data (when loaded)
            'medical_records' => $this->whenLoaded('medicalRecords'),
            'prescriptions' => $this->whenLoaded('prescriptions'),
            'vaccinations' => $this->whenLoaded('vaccinations'),

            // Timestamps
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * `AppointmentStatus` é a fonte única dos rótulos — status desconhecido (dado legado ou
     * inconsistente) ainda assim mostra algo em vez de quebrar a resposta.
     */
    private function getStatusLabel(): string
    {
        return AppointmentStatus::tryFrom($this->status ?? '')?->label()
            ?? ucfirst($this->status ?? '');
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
            'hospitalization' => 'Internação',
            default => ucfirst($this->type ?? ''),
        };
    }
}
