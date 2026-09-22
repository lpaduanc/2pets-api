<?php

namespace App\Http\Requests\Commercial;

use App\Enums\ServiceCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Contrato docs/gap-simplesvet/08-produtos-precificacao-lista-precos.md — campos comerciais de
 * `services`. Extraído do `Validator::make` inline de `App\Http\Controllers\Api\ServiceController`
 * (achado do frontend-specialist: os campos comerciais não estavam na lista de regras, então
 * `validated()` os descartava silenciosamente e a tela nova salvava sem persistir nada).
 */
class StoreServiceRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['required', Rule::enum(ServiceCategory::class)],
            'duration' => ['required', 'integer'],
            'price' => ['required', 'numeric'],
            'active' => ['boolean'],

            'code' => ['nullable', 'string', 'max:60'],
            'commission_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'product_group_id' => ['nullable', 'integer', 'exists:product_groups,id'],
            'municipal_service_code' => ['nullable', 'string', 'max:20'],
            'lc116_code' => ['nullable', 'string', 'max:10'],
            'show_in_price_list' => ['nullable', 'boolean'],
            'allow_price_override' => ['nullable', 'boolean'],

            // Fase 6 do fluxo de agendamento — override de sinal por serviço. `deposit_enabled`
            // ausente/`null`: sem override, herda do estabelecimento (`DepositConfigResolver`).
            'deposit_enabled' => ['nullable', 'boolean'],
            'deposit_percentage' => ['nullable', 'numeric', 'min:0', 'max:50'],
        ];
    }
}
