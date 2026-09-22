<?php

namespace App\Http\Requests\Commercial;

use App\Enums\ClientAccountEntryType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ajuste manual / adiantamento da conta corrente do cliente — contrato
 * docs/gap-simplesvet/11-conta-corrente-do-cliente-spec.md. `direction` não é aceita do
 * cliente: é derivada do `type` em `ClientAccountService::recordManualEntry()` (mais seguro
 * que confiar num par `type`/`direction` que o app poderia mandar incoerente).
 */
class StoreClientAccountEntryRequest extends FormRequest
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
            'type' => ['required', Rule::enum(ClientAccountEntryType::class)->only(ClientAccountEntryType::manuallyRecordable())],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
