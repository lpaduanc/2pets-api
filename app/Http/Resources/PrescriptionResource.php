<?php

namespace App\Http\Resources;

use App\Models\Pet;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrato único da prescrição — usado por index/show/store/update e por `prescriptions/valid`.
 *
 * Três garantias que a tela do profissional depende e que o `toArray()` do model não dava:
 *
 *   1. `prescription_date` e `valid_until` saem em `Y-m-d`. São datas de calendário: serializar
 *      como ISO datetime (`2026-05-04T00:00:00.000000Z`) faz o cliente aplicar fuso e exibir o
 *      dia anterior.
 *   2. `medications` é SEMPRE uma lista de objetos. Linhas legadas foram gravadas
 *      duplo-encodadas (`json_encode` no controller + cast `array` no model), e nessas o cast
 *      devolve string — o cliente iterava os caracteres da string. A migration
 *      `repair_double_encoded_prescription_medications` normalizou a base; o desencapsulamento
 *      aqui é a segunda linha de defesa, para que nenhuma linha residual quebre a tela.
 *   3. `pet.tutor` vem embutido. O model chama a relação de `user`; o contrato da API usa
 *      `tutor`, que é o vocabulário do produto.
 *
 * Exige `pet.user` e `professional` eager-loaded — `Model::preventLazyLoading()` está ativo
 * fora de produção, então esquecer o eager load falha alto, não em silêncio.
 *
 * @mixin \App\Models\Prescription
 */
class PrescriptionResource extends JsonResource
{
    /** Profundidade máxima de desencapsulamento de um valor duplo-encodado legado. */
    private const MAX_ENCODING_DEPTH = 3;

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
            'prescription_date' => $this->prescription_date?->format('Y-m-d'),
            'valid_until' => $this->valid_until?->format('Y-m-d'),
            'is_controlled' => (bool) $this->is_controlled,
            'medications' => $this->medicationList(),
            'general_instructions' => $this->general_instructions,
            'warnings' => $this->warnings,
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

    /**
     * @return list<mixed>
     */
    private function medicationList(): array
    {
        $medications = $this->decodeMedications($this->medications);

        if ($medications === []) {
            return [];
        }

        // Linha legada gravada como objeto único em vez de lista: embrulha em vez de descartar.
        return array_is_list($medications) ? $medications : [$medications];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decodeMedications(mixed $value): array
    {
        $depth = 0;

        while (is_string($value) && $depth < self::MAX_ENCODING_DEPTH) {
            $value = json_decode($value, true);
            $depth++;
        }

        return is_array($value) ? $value : [];
    }
}
