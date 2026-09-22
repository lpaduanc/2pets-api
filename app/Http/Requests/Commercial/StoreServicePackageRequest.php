<?php

namespace App\Http\Requests\Commercial;

use App\Enums\PackageValidityType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Contrato docs/gap-simplesvet/specs/10-pacotes-de-servicos-vendidos-spec.md. Serve store e
 * update: no update todo campo vira opcional via `sometimes`, o controller decide o resto.
 */
class StoreServicePackageRequest extends FormRequest
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
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'price' => [$required, 'numeric', 'min:0', 'max:9999999.99'],

            'validity_type' => [$required, Rule::enum(PackageValidityType::class)],
            'validity_days' => ['nullable', 'integer', 'min:1'],
            'fixed_expires_at' => ['nullable', 'date'],

            'allow_transfer_between_pets' => ['nullable', 'boolean'],
            'commission_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'show_in_price_list' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],

            'items' => [$required, 'array', 'min:1'],
            'items.*.service_id' => ['required', 'integer', 'distinct', 'exists:services,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * A obrigatoriedade cruzada por `validity_type` (regra de negócio 3) não dá pra expressar
     * só com `required_if` porque os dois campos são mutuamente exclusivos, não aditivos —
     * `days_from_sale` exige `validity_days` e PROÍBE `fixed_expires_at`, e vice-versa.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = $this->input('validity_type');

            if ($type === PackageValidityType::DAYS_FROM_SALE->value && ! $this->filled('validity_days')) {
                $validator->errors()->add('validity_days', 'Informe a quantidade de dias de validade.');
            }

            if ($type === PackageValidityType::FIXED_DATE->value && ! $this->filled('fixed_expires_at')) {
                $validator->errors()->add('fixed_expires_at', 'Informe a data fixa de validade.');
            }
        });
    }
}
