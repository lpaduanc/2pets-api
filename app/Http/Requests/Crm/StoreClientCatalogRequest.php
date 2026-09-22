<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Corpo idêntico para `client-origins` e `churn-reasons` — os dois catálogos têm exatamente a
 * mesma forma (`name`, `active`). Autorização de escrita fica no Gate/Policy do controller,
 * não aqui (mesmo padrão de `StoreImmunizationProductRequest`).
 */
class StoreClientCatalogRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:150'],
            'active' => ['nullable', 'boolean'],
        ];
    }
}
