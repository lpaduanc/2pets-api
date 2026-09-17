<?php

namespace App\Http\Requests\Prescription;

use App\Models\Prescription;

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
        ], $this->itemRules(), $this->contentRules());
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
        $prescription = Prescription::query()
            ->where('professional_id', $this->user()?->id)
            ->find($this->route('prescription'));

        return $prescription?->prescription_date?->format('Y-m-d');
    }
}
