<?php

namespace App\Http\Requests\Commercial;

use App\Services\Commercial\CommercialScopeResolver;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Contrato docs/gap-simplesvet/01-caixa-pdv.md — "Abrir caixa por usuário e data".
 *
 * `opening_amount` é opcional e default 0: abrir caixa sem troco é legítimo (loja que só
 * recebe em cartão e Pix). A regra de "um caixa aberto por pessoa" NÃO fica aqui — é do
 * `CashRegisterService`, que é quem tem a query, e do índice único do banco, que é quem
 * segura a corrida.
 */
class OpenCashRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && app(CommercialScopeResolver::class)->canOperateCounter($this->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'opening_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'opening_amount.min' => 'O valor de abertura não pode ser negativo.',
        ];
    }
}
