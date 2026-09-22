<?php

namespace App\Http\Requests\Commercial;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Contrato docs/gap-simplesvet/specs/10-pacotes-de-servicos-vendidos-spec.md — baixa de sessão.
 * `sold_package_item_id` pertencer ao pacote da rota é validado no controller, não aqui: a
 * regra depende do `SoldPackage` já carregado pelo route model binding.
 */
class ConsumeSoldPackageRequest extends FormRequest
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
            'sold_package_item_id' => ['required', 'integer', 'exists:sold_package_items,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'appointment_id' => ['nullable', 'integer', 'exists:appointments,id'],
            'medical_record_id' => ['nullable', 'integer', 'exists:medical_records,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
