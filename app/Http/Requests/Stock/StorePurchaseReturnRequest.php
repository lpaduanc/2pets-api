<?php

namespace App\Http\Requests\Stock;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Devolução de compra (spec fase4-fiscal §3.6). Serve o `POST purchase-returns` e a prévia
 * (`POST purchases/{id}/returns/preview`, sem `purchase_id`/`reason`). Quantidade na unidade da
 * compra, até 4 casas.
 */
class StorePurchaseReturnRequest extends FormRequest
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
        $isPreview = $this->route('id') !== null;

        return [
            'purchase_id' => [$isPreview ? 'prohibited' : 'required', 'integer'],
            'reason' => [$isPreview ? 'nullable' : 'required', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_item_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
        ];
    }
}
