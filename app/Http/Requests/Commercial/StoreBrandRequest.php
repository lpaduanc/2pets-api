<?php

namespace App\Http\Requests\Commercial;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Contrato docs/gap-simplesvet/08-produtos-precificacao-lista-precos.md — "Grupos e marcas".
 * Serve store e update (ver `StoreProductGroupRequest` para o porquê do `$required`).
 */
class StoreBrandRequest extends FormRequest
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
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            'active' => ['nullable', 'boolean'],
        ];
    }
}
