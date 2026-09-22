<?php

namespace App\Http\Requests\Stock;

use App\Enums\StockMovementType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Lançamento manual — só os tipos de `StockMovementType::manual()`. Venda, compra, devolução
 * e inventário têm fluxo próprio e não podem ser lançados por aqui.
 */
class StoreStockMovementRequest extends FormRequest
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
            'product_id' => ['required', 'integer'],
            'type' => ['required', Rule::in(array_map(fn (StockMovementType $t) => $t->value, StockMovementType::manual()))],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'reason_id' => ['nullable', 'integer'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'batch_id' => ['nullable', 'integer'],
            'batch_code' => ['nullable', 'string', 'max:60'],
            'expires_at' => ['nullable', 'date'],
            'occurred_at' => ['nullable', 'date', 'before_or_equal:now'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
