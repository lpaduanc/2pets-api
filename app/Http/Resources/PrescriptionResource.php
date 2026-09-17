<?php

namespace App\Http\Resources;

use App\Models\Pet;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrato único da prescrição — usado por index/show/store/update, `prescriptions/valid`,
 * `issue` e `cancel`.
 *
 * Garantias que a tela do profissional depende e que o `toArray()` do model não dava:
 *
 *   1. `prescription_date`/`valid_until`/`issued_at`/`canceled_at` saem em formato estável.
 *      As duas primeiras são datas de calendário (`Y-m-d`): serializar como ISO datetime
 *      faz o cliente aplicar fuso e exibir o dia anterior.
 *   2. `items` é a lista estruturada de `PrescriptionItem` (contrato
 *      docs/atendimento-veterinario/03-contrato-receituario.md §2) — substitui o antigo
 *      array `medications` (JSON).
 *   3. `pet.tutor` vem embutido. O model chama a relação de `user`; o contrato da API usa
 *      `tutor`, que é o vocabulário do produto.
 *
 * NUNCA expõe `control_number`/`signature_type`/`signed_at`/`verification_code`/`hash` —
 * colunas preparatórias da fatia de assinatura digital, contrato §2: "não lidas, não expostas
 * nesta fatia".
 *
 * Exige `Prescription::RESOURCE_RELATIONS` eager-loaded — `Model::preventLazyLoading()` está
 * ativo fora de produção, então esquecer o eager load falha alto, não em silêncio.
 *
 * @mixin \App\Models\Prescription
 */
class PrescriptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'pet' => $this->petPayload(),
            'professional' => $this->professionalPayload(),
            'appointment_id' => $this->appointment_id,
            'medical_record_id' => $this->medical_record_id,
            'standalone_reason' => $this->standalone_reason,
            'kind' => $this->kind?->value,
            'prescription_date' => $this->prescription_date?->format('Y-m-d'),
            'valid_until' => $this->valid_until?->format('Y-m-d'),
            'is_controlled' => (bool) $this->is_controlled,
            'items' => PrescriptionItemResource::collection($this->whenLoaded('items')),
            'general_instructions' => $this->general_instructions,
            'warnings' => $this->warnings,
            'is_editable' => $this->isEditable(),
            'issued_at' => $this->issued_at?->toISOString(),
            'canceled_at' => $this->canceled_at?->toISOString(),
            'canceled_reason' => $this->canceled_reason,
            'canceled_by' => $this->participantPayload($this->canceledBy),
            'supersedes_id' => $this->supersedes_id,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function petPayload(): ?array
    {
        $pet = $this->pet;

        if (! $pet instanceof Pet) {
            return null;
        }

        return [
            'id' => $pet->id,
            'name' => $pet->name,
            'species' => $pet->species,
            'photo_url' => $pet->image_url,
            'tutor' => $this->participantPayload($pet->user),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function professionalPayload(): ?array
    {
        return $this->participantPayload($this->professional);
    }

    /**
     * Só id e nome: prescrição é tela de consulta clínica, não de contato. Expor e-mail,
     * telefone ou CPF aqui vazaria PII que a tela não usa.
     *
     * @return array<string, mixed>|null
     */
    private function participantPayload(?User $participant): ?array
    {
        if ($participant === null) {
            return null;
        }

        return [
            'id' => $participant->id,
            'name' => $participant->name,
        ];
    }
}
