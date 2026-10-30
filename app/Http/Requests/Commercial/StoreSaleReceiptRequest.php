<?php

namespace App\Http\Requests\Commercial;

use App\Enums\CardBrand;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Recebimento de uma venda — contrato docs/gap-simplesvet/01-caixa-pdv.md.
 *
 * Um recebimento por chamada. "Dinheiro + cartão na mesma venda" são DUAS chamadas, e é assim
 * que o critério de aceite pede ("gera 2 `sale_receipts` e 2 movimentos de caixa"). Aceitar um
 * array de formas aqui esconderia o fato de que cada uma tem taxa e prazo próprios.
 *
 * `operator_fee` e `net_amount` NÃO são aceitos do cliente: são calculados no servidor a
 * partir da forma cadastrada (doc 04). Deixar o app mandar a taxa permitiria zerar a despesa
 * de adquirente no DRE.
 */
class StoreSaleReceiptRequest extends FormRequest
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
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999.99'],
            'installments' => ['nullable', 'integer', 'min:1', 'max:24'],
            // Cartão (docs/gap-petflow 1.1): a bandeira decide a faixa de taxa; autorização e
            // terminal ajudam a casar o extrato da adquirente.
            'card_brand' => ['nullable', Rule::in(CardBrand::values())],
            'authorization_code' => ['nullable', 'string', 'max:40'],
            'terminal_label' => ['nullable', 'string', 'max:60'],
            'account_id' => ['nullable', 'integer', 'exists:financial_accounts,id'],
            'received_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payment_method_id.required' => 'Escolha a forma de recebimento.',
            'amount.gt' => 'O valor recebido deve ser maior que zero.',
        ];
    }
}
