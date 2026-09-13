<?php

namespace App\Http\Requests\Invoice;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInvoiceRequest extends FormRequest
{
    /** Espelha o CHECK/enum nativo da coluna `invoices.status`. */
    private const STATUSES = ['pending', 'paid', 'overdue', 'cancelled'];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * `subtotal` e `total` não são validados aqui de propósito: quando `items` é enviado, os
     * dois são recalculados no servidor (`InvoiceTotalsCalculator`); quando não é, os valores
     * atuais da fatura são preservados — ver `InvoiceController::update`.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'due_date' => ['sometimes', 'date'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'discount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'tax' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'status' => ['sometimes', 'in:'.implode(',', self::STATUSES)],
            'payment_method' => ['nullable', 'string', 'max:50'],
            'payment_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
