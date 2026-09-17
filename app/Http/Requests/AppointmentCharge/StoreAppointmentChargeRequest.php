<?php

namespace App\Http\Requests\AppointmentCharge;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Contrato docs/atendimento-veterinario/09-faturamento-do-atendimento.md §13.3/§13.7:
 * `service_id` é opcional — quando vier sem `description`/`unit_price`, o servidor
 * preenche a partir do catálogo (`AppointmentChargeService::resolveService`). Sem
 * `service_id`, os dois passam a ser obrigatórios: não há de onde derivá-los.
 *
 * `reference_date` (contrato docs/atendimento-veterinario/
 * 11-internacao-no-fluxo-de-faturamento.md §2.1): opcional, só a diária de internação usa —
 * desambigua lançamento retroativo da data do lançamento em si (`created_at`).
 */
class StoreAppointmentChargeRequest extends FormRequest
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
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'description' => ['required_without:service_id', 'nullable', 'string', 'max:255'],
            'quantity' => ['nullable', 'numeric', 'min:0.01'],
            'unit_price' => ['required_without:service_id', 'nullable', 'numeric', 'min:0'],
            'reference_date' => ['nullable', 'date'],
        ];
    }
}
