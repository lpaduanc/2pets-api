<?php

namespace App\Http\Requests\Commercial;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Contagem da gaveta no fechamento — contrato docs/gap-simplesvet/01-caixa-pdv.md,
 * "conferência cega por forma de pagamento".
 *
 * `counted` chega como mapa `{payment_method_id: valor}`. O esperado NÃO vem no payload: quem
 * conta não pode ver o esperado antes (é o que "cega" significa), e aceitá-lo do cliente
 * permitiria forjar a conferência mandando esperado = contado.
 */
class CloseCashRegisterRequest extends FormRequest
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
            'counted' => ['required', 'array'],
            // A chave é o id da forma de pagamento, ou a string "none" para o movimento
            // manual sem forma (ajuste). `array_keys` de JSON vem como string nos dois casos.
            'counted.*' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'counted.required' => 'Informe o valor contado de cada forma de pagamento.',
            'counted.*.min' => 'Valor contado não pode ser negativo.',
        ];
    }
}
