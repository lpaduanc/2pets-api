<?php

namespace App\Http\Requests\Invoice;

use App\Services\Professional\ProfessionalClientsQuery;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Contrato docs/atendimento-veterinario/09-faturamento-do-atendimento.md §12.4, revisado pelo §13: fatura
 * originada de atendimento (`appointment_id != null`) deriva `client_id` do pet, sem
 * passar por aqui — só esta rota manual (`POST /invoices`, sem pet) aceita `client_id` do
 * payload, e por isso precisa validar que é um cliente real deste profissional. Antes só
 * `exists:users,id`, o que permitia faturar contra qualquer usuário da plataforma por
 * enumeração de id — mesma classe de furo já corrigida em `StoreAppointmentRequest`
 * (docs/atendimento-veterinario/08-consulta-autorizada-por-agendamento.md §E).
 */
class StoreInvoiceRequest extends FormRequest
{
    /** Espelha o CHECK/enum nativo da coluna `invoices.status`. */
    private const STATUSES = ['pending', 'paid', 'overdue', 'cancelled'];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('client_id')) {
                return;
            }

            $isClient = app(ProfessionalClientsQuery::class)
                ->isClientOf((int) $this->user()->id, (int) $this->input('client_id'));

            if (! $isClient) {
                $validator->errors()->add('client_id', 'Este cliente ainda não pertence à sua carteira.');
            }
        });
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
