<?php

namespace App\Http\Requests\Stock;

/**
 * Prévia da devolução (`POST sales/{sale}/returns/preview`): o MESMO corpo do `POST sale-returns`,
 * para a tela mandar o que vai salvar. A venda vem da URL — `sale_id` no corpo é aceito, mas
 * tem de ser a mesma — e o motivo ainda pode estar em branco enquanto o usuário escolhe os itens.
 */
class PreviewSaleReturnRequest extends StoreSaleReturnRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sale_id' => ['nullable', 'integer', 'in:'.(int) $this->route('sale')],
            'reason' => ['nullable', 'string', 'max:2000'],
        ] + parent::rules();
    }
}
