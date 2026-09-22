<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrato docs/atendimento-veterinario/11-internacao-no-fluxo-de-faturamento.md e
 * docs/atendimento-veterinario/12-modulo-clinico-internacao.md.
 *
 * `total_cost` NÃO aparece aqui de propósito (doc 11 §4) — o valor cobrado vem sempre de
 * `appointment.invoice_id` (o `GET /invoices/{id}` correspondente), nunca de um campo
 * próprio da internação. `daily_notes` também não aparece mais (doc 12 §2): aposentado a
 * favor de `progress_notes` — não é mais gravável, e expô-lo aqui só confundiria uma tela
 * que não deve mais tratá-lo como prontuário.
 *
 * `missing_daily_charges_count`/`hours_since_last_progress_note`/`follow_up_appointment` são
 * avisos/derivações em leitura (mesma família de query por linha, aceita neste Resource desde
 * o doc 11), nunca bloqueantes. `indicating_medical_record` é só o resumo (diagnóstico/plano)
 * do prontuário de OUTRO atendimento que indicou internar — nunca o registro inteiro, para
 * não puxar as relações pesadas de `MedicalRecord::RESOURCE_RELATIONS` só para mostrar um
 * cabeçalho.
 */
class HospitalizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'pet_id' => $this->pet_id,
            'professional_id' => $this->professional_id,
            'appointment_id' => $this->appointment_id,
            'indicating_medical_record_id' => $this->indicating_medical_record_id,
            'box_id' => $this->box_id,
            'admission_date' => $this->admission_date?->toDateString(),
            'discharge_date' => $this->discharge_date?->toDateString(),
            'estimated_discharge_date' => $this->estimated_discharge_date?->toDateString(),
            'reason' => $this->reason,
            'risk_level' => $this->risk_level?->value,
            'status' => $this->status,
            'discharge_summary' => $this->discharge_summary,
            'medications' => $this->medications,
            'missing_daily_charges_count' => $this->resource->missingDailyChargesCount(),
            'hours_since_last_progress_note' => $this->resource->hoursSinceLastProgressNote(),
            'progress_note_overdue_after_hours' => config('hospitalization.progress_note_overdue_hours'),

            'pet' => new PetResource($this->whenLoaded('pet')),
            'professional' => new UserResource($this->whenLoaded('professional')),
            'appointment' => new AppointmentResource($this->whenLoaded('appointment')),
            'indicating_medical_record' => $this->whenLoaded(
                'indicatingMedicalRecord',
                fn () => $this->indicatingMedicalRecordSummary(),
            ),
            'progress_notes' => HospitalizationProgressNoteResource::collection($this->whenLoaded('progressNotes')),
            'care_logs' => HospitalizationCareLogResource::collection($this->whenLoaded('careLogs')),
            'follow_up_appointment' => $this->followUpAppointmentResource(),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * Mesmo formato de `AppointmentResource` já usado no resto da API — sem campo novo tipo
     * `starts_at`; o cliente lê `appointment_date`/`appointment_time`, como em qualquer outro
     * agendamento.
     */
    private function followUpAppointmentResource(): ?AppointmentResource
    {
        $appointment = $this->resource->followUpAppointment();

        return $appointment !== null ? new AppointmentResource($appointment) : null;
    }

    /**
     * `whenLoaded` só garante que a RELAÇÃO foi carregada — a internação pode não ter
     * `indicating_medical_record_id` nenhum, e nesse caso o valor carregado é `null`.
     *
     * @return array{id: int, record_date: ?string, diagnosis: ?string, plan: ?string, treatment_plan: ?string}|null
     */
    private function indicatingMedicalRecordSummary(): ?array
    {
        $record = $this->indicatingMedicalRecord;

        if ($record === null) {
            return null;
        }

        return [
            'id' => $record->id,
            'record_date' => $record->record_date?->toDateString(),
            'diagnosis' => $record->diagnosis,
            'plan' => $record->plan,
            'treatment_plan' => $record->treatment_plan,
        ];
    }
}
