<?php

namespace App\Http\Requests\Commercial;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `credit_limit`/`allow_credit_sale` de um cliente — contrato
 * docs/gap-simplesvet/11-conta-corrente-do-cliente-spec.md. Só `OWNER`
 * (`CompanyClientAccountPolicy::manage`).
 */
class UpdateClientAccountSettingsRequest extends FormRequest
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
            'credit_limit' => ['required', 'numeric', 'min:0'],
            'allow_credit_sale' => ['required', 'boolean'],
        ];
    }
}
