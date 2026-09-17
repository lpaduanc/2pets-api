<?php

namespace App\Http\Resources;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrato único do prontuário — `start`/`walk-in`, leitura do tutor e leitura do
 * profissional (docs/atendimento-veterinario/01-contrato-api-e-taxonomia.md §1 "Regra de
 * resposta" e §6).
 *
 * Exige `MedicalRecord::RESOURCE_RELATIONS` eager-loaded quando disponíveis —
 * `Model::preventLazyLoading()` está ativo fora de produção.
 *
 * @mixin \App\Models\MedicalRecord
 */
class MedicalRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewedByAuthor = $request->user()?->id === $this->professional_id;

        $summary = ['summary_for_tutor' => $this->summary_for_tutor];
        $core = $this->corePayload();

        // Contrato §1/§6: quando quem lê NÃO é o autor (tipicamente o tutor, ou um vet
        // colega com PetVetAccess), o resumo em linguagem leiga vem primeiro no payload —
        // o corpo técnico continua presente por inteiro logo em seguida (direito de cópia
        // integral, Res. CFMV 1.653/2025), nunca omitido.
        return $viewedByAuthor ? [...$core, ...$summary] : [...$summary, ...$core];
    }

    /**
     * @return array<string, mixed>
     */
    private function corePayload(): array
    {
        return [
            'id' => $this->id,
            'pet_id' => $this->pet_id,
            'professional_id' => $this->professional_id,
            'appointment_id' => $this->appointment_id,
            // Contrato docs/atendimento-veterinario/09-faturamento-do-atendimento.md §13.5:
            // permite o front, logo depois de finalizar, abrir a fatura `pending` do
            // atendimento com um único GET, sem inventar filtro novo. `null` para
            // agendamento sem serviço nenhum lançado (invariante 11) — não é erro.
            'invoice_id' => $this->whenLoaded('invoice', fn (?Invoice $invoice): ?int => $invoice?->id, null),
            'record_date' => $this->record_date?->format('Y-m-d'),
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'finalized_at' => $this->finalized_at?->toISOString(),

            // Contrato docs/atendimento-veterinario/11-internacao-no-fluxo-de-faturamento.md
            // §2.2: só presente quando este prontuário é um ato clínico Grupo A aberto
            // DURANTE uma internação — distingue múltiplos atos pendurados no mesmo
            // `appointment_id` da internação. `null` no fluxo normal (um agendamento, um
            // prontuário, `appointment.type` já basta).
            'act_category' => $this->act_category?->value,
            'act_category_label' => $this->act_category?->label(),

            'chief_complaint' => $this->chief_complaint,
            'chief_complaint_notes' => $this->chief_complaint_notes,
            'anamnesis_signs' => $this->anamnesis_signs,
            'behavior_findings' => $this->behavior_findings,
            'recent_routine_change' => $this->recent_routine_change,
            'recent_routine_change_notes' => $this->recent_routine_change_notes,
            'context_flags' => $this->context_flags,

            'weight' => $this->weight !== null ? (float) $this->weight : null,
            'temperature' => $this->temperature !== null ? (float) $this->temperature : null,
            'heart_rate' => $this->heart_rate,
            'respiratory_rate' => $this->respiratory_rate,
            'physical_exam' => $this->physical_exam,
            'capillary_refill_time' => $this->capillary_refill_time,
            'hydration_status' => $this->hydration_status,
            'body_condition_score' => $this->body_condition_score,
            'pain_score' => $this->pain_score,

            'subjective' => $this->subjective,
            'objective' => $this->objective,
            'assessment' => $this->assessment,
            'plan' => $this->plan,
            'symptoms' => $this->symptoms,
            'diagnosis' => $this->diagnosis,
            'diagnosis_status' => $this->diagnosis_status,
            'treatment_plan' => $this->treatment_plan,
            'treatment_actions' => $this->treatment_actions,
            // `prescriptions` = `Prescription` estruturada vinculada a este atendimento
            // (contrato docs/atendimento-veterinario/03-contrato-receituario.md §2), não a
            // coluna JSON legada — ver `MedicalRecord::linkedPrescriptions()` para o motivo
            // do nome de relação diferente. `legacy_prescriptions` continua expondo o texto
            // antigo, somente leitura, para nenhum prontuário já finalizado perder o que foi
            // escrito ali antes desta fatia.
            'prescriptions' => PrescriptionResource::collection($this->whenLoaded('linkedPrescriptions')),
            'legacy_prescriptions' => $this->prescriptions,
            'notes' => $this->notes,

            'previous_record_id' => $this->previous_record_id,
            'follow_up_appointment_id' => $this->follow_up_appointment_id,

            // Contrato docs/atendimento-veterinario/08-consulta-autorizada-por-agendamento.md
            // §C: o que o tutor informou NA consulta, à parte do cadastro do pet. Promover ao
            // cadastro é ação exclusiva do tutor (§D) — nunca escrito por aqui.
            'reported_pet_data' => $this->reported_pet_data,
            'reported_pet_data_applied_at' => $this->reported_pet_data_applied_at?->toISOString(),
            'reported_pet_data_applied_by' => new UserResource($this->whenLoaded('reportedPetDataAppliedBy')),

            'pet' => new PetResource($this->whenLoaded('pet')),
            'professional' => new UserResource($this->whenLoaded('professional')),
            'finalized_by' => new UserResource($this->whenLoaded('finalizer')),
            'addenda' => MedicalRecordAddendumResource::collection($this->whenLoaded('addenda')),
            'attachments' => MedicalRecordAttachmentResource::collection($this->whenLoaded('attachments')),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
