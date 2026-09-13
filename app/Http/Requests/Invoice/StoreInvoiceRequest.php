<?php

namespace App\Http\Requests\Invoice;

use Illuminate\Foundation\Http\FormRequest;

class StoreInvoiceRequest extends FormRequest
{
    /** Espelha o CHECK/enum nativo da coluna `invoices.status`. */
    private const STATUSES = ['pending', 'paid', 'overdue', 'cancelled'];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * `subtotal` e `total` não são validados aqui de propósito: nascem calculados no servidor
     * a partir de `items` + `discount` + `tax` (`InvoiceTotalsCalculator`), nunca do que o
     * cliente mandar — ver `InvoiceController::store`.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'exists:users,id'],
            'appointment_id' => ['nullable', 'exists:appointments,id'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:issue_date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'tax' => ['nullable', 'numeric', 'min:0'],
            'status' => ['required', 'in:'.implode(',', self::STATUSES)],
            'payment_method' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
