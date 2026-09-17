<?php

namespace App\Http\Requests\Invoice;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Contrato docs/atendimento-veterinario/09-faturamento-do-atendimento.md §4:
 * `{ method, paid_at?, notes? }`. `method` aceita `cash` — o profissional está declarando
 * recebimento presencial, não passando pelo gateway.
 *
 * `credit_resolution` (contrato docs/atendimento-veterinario/
 * 11-internacao-no-fluxo-de-faturamento.md §3-bis.3): só passa a ser exigido em runtime
 * quando a fatura tem sobra de adiantamento (`PaymentService::assertCreditResolutionProvidedIfNeeded`)
 * — não dá para saber isso aqui sem consultar a fatura, então a obrigatoriedade fica no
 * Service, não nesta regra estática.
 */
class MarkInvoiceAsPaidRequest extends FormRequest
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
            'method' => ['required', 'string', Rule::in(PaymentMethod::values())],
            'paid_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'credit_resolution' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
