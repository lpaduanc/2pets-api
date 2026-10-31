<?php

namespace App\Http\Requests\Commercial;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Corpo do `POST pricing/simulate` (docs/gap-petflow/PLANO.md, 1.4). Exatamente um entre `price`,
 * `margin_percent` e `markup_percent` define o preço; `item_type` + `item_id` só servem para
 * buscar a comissão do item quando `commission_percent` não vem. Casas: custo 4, preço 2,
 * percentual 4 — mais casas é recusado, não arredondado.
 */
class SimulatePricingRequest extends FormRequest
{
    private const PRICE_TARGETS = ['price', 'margin_percent', 'markup_percent'];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cost' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:99999999.9999'],
            'price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            'margin_percent' => ['nullable', 'numeric', 'decimal:0,4', 'min:-100000', 'max:99.9999'],
            'markup_percent' => ['nullable', 'numeric', 'decimal:0,4', 'min:-100', 'max:1000000'],
            'commission_percent' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:100'],
            'item_type' => ['nullable', 'required_with:item_id', 'in:product,service'],
            'item_id' => ['nullable', 'required_with:item_type', 'integer'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['margin_percent.max' => __('pricing.margin_below_hundred')];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $informed = array_filter(self::PRICE_TARGETS, fn (string $field): bool => $this->filled($field));

            if (count($informed) !== 1) {
                $validator->errors()->add('price', __('pricing.single_price_target'));
            }
        }];
    }
}
