<?php

namespace App\Http\Requests\Prescription;

use App\Models\Prescription;
use Illuminate\Validation\Rule;

class UpdatePrescriptionRequest extends PrescriptionRequest
{
    /**
     * `pet_id` fica de fora de propósito: mover uma prescrição para outro pet reescreveria
     * histórico clínico e escaparia do gate de acesso já validado na criação. A imutabilidade
     * de uma prescrição já emitida (contrato §1) NÃO é checada aqui — é
     * `PrescriptionWriteService::update()` quem recusa, porque depende do estado persistido,
     * não da forma do payload.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            'prescription_date' => 'sometimes|required|date',
            'valid_until' => 'nullable|date|after_or_equal:prescription_date',
            'items' => 'sometimes|required|array|min:1|max:'.self::MAX_ITEMS,
        ], $this->itemRules(), $this->contentRules(), [
            'standalone_reason' => [
                Rule::requiredIf(fn (): bool => $this->leavesPrescriptionWithoutReason()),
                'nullable',
                'string',
                'max:255',
            ],
        ]);
    }

    /**
     * Edição é PARCIAL: o `required_without:medical_record_id` da criação reprovaria qualquer
     * PUT que não reenviasse o vínculo (ex.: só trocar os itens). Aqui o motivo só é exigido
     * quando a própria edição desvincula do atendimento (`medical_record_id: null`) e a
     * prescrição ainda não tem motivo gravado.
     */
    private function leavesPrescriptionWithoutReason(): bool
    {
        if (! $this->exists('medical_record_id') || $this->filled('medical_record_id')) {
            return false;
        }

        return blank($this->persistedPrescription()?->standalone_reason);
    }

    /**
     * `after_or_equal:prescription_date` só compara com outro campo quando ele está no payload.
     * Numa edição parcial que manda só `valid_until`, o Laravel trataria "prescription_date"
     * como literal de data, `strtotime()` falharia e a regra reprovaria qualquer valor. Repor a
     * data já persistida faz a comparação valer contra o dado real; como o valor é idêntico ao
     * gravado, o `update()` resultante é no-op para essa coluna.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('prescription_date')) {
            return;
        }

        $persistedDate = $this->persistedPrescriptionDate();

        if ($persistedDate === null) {
            return;
        }

        $this->merge(['prescription_date' => $persistedDate]);
    }

    private function persistedPrescriptionDate(): ?string
    {
        return $this->persistedPrescription()?->prescription_date?->format('Y-m-d');
    }

    private function persistedPrescription(): ?Prescription
    {
        return Prescription::query()
            ->where('professional_id', $this->user()?->id)
            ->find($this->route('prescription'));
    }
}
