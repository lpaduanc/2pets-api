<?php

namespace App\Http\Requests\Commercial;

use App\Enums\ServiceCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Contrato docs/gap-simplesvet/08-produtos-precificacao-lista-precos.md. Irmã de
 * `StoreServiceRequest`: mesmos campos, todos `sometimes` porque o `update` aceita edição
 * parcial (mesmo padrão do `ServiceController` antes da extração).
 */
class UpdateServiceRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['sometimes', 'required', Rule::enum(ServiceCategory::class)],
            'duration' => ['sometimes', 'required', 'integer'],
            'price' => ['sometimes', 'required', 'numeric'],
            'active' => ['boolean'],

            'code' => ['nullable', 'string', 'max:60'],
            'commission_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'product_group_id' => ['nullable', 'integer', 'exists:product_groups,id'],
            'municipal_service_code' => ['nullable', 'string', 'max:20'],
            'lc116_code' => ['nullable', 'string', 'max:10'],
            'show_in_price_list' => ['nullable', 'boolean'],
            'allow_price_override' => ['nullable', 'boolean'],

            'deposit_enabled' => ['nullable', 'boolean'],
            'deposit_percentage' => ['nullable', 'numeric', 'min:0', 'max:50'],
        ];
    }
}
