<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrato docs/atendimento-veterinario/10-taxonomia-servico-tipo-atendimento.md §2/§3:
 * resposta de `POST professional/appointments/{id}/start` quando o agendamento é categoria
 * `laboratory`/`imaging` — o laudo (`ExamResult`) é outro fluxo (`ExamController`), aqui só
 * o registro mínimo do ato (`exam_type`+`exam_date`+`status`).
 *
 * @mixin \App\Models\Exam
 */
class ExamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'pet_id' => $this->pet_id,
            'professional_id' => $this->professional_id,
            'appointment_id' => $this->appointment_id,
            'exam_type' => $this->exam_type,
            'exam_name' => $this->exam_name,
            'exam_date' => $this->exam_date?->format('Y-m-d'),
            'status' => $this->status,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
