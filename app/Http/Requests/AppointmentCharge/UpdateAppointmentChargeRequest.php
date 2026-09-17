<?php

namespace App\Http\Requests\AppointmentCharge;

use Illuminate\Foundation\Http\FormRequest;

/** Contrato §13.3/§13.7: mesmos campos do `store`, todos opcionais — atualização parcial. */
class UpdateAppointmentChargeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'service_id' => ['sometimes', 'nullable', 'integer', 'exists:services,id'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'quantity' => ['sometimes', 'nullable', 'numeric', 'min:0.01'],
            'unit_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'reference_date' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
