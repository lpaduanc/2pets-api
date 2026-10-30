<?php

namespace App\Http\Requests\Stock;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Prévia do custo real da compra (`POST purchases/cost-preview`) — docs/gap-petflow/PLANO.md,
 * 1.2. Mesmo corpo de custo do `StorePurchaseRequest` (fornecedor e produto não importam para a
 * conta) e mesma autorização de criar compra.
 */
class PreviewPurchaseCostRequest extends FormRequest
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
        return StorePurchaseRequest::costRules() + [
            // Para o preço sugerido: markup do item, ou o do produto quando só vem o id.
            'items.*.product_id' => ['nullable', 'integer'],
            'items.*.markup_percent' => ['nullable', 'numeric', 'min:-100', 'max:100000'],
        ];
    }
}
