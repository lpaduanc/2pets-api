<?php

namespace App\Http\Requests\Commercial;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Contrato docs/gap-simplesvet/08-produtos-precificacao-lista-precos.md — "Grupos e marcas".
 * Serve store e update: no update todo campo vira opcional via `sometimes` no controller,
 * que é o único lugar que sabe se está criando ou editando.
 */
class StoreProductGroupRequest extends FormRequest
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
            'parent_id' => ['nullable', 'integer', 'exists:product_groups,id'],
            'default_markup_percent' => ['nullable', 'numeric', 'min:-100', 'max:100000'],
            'active' => ['nullable', 'boolean'],
        ];
    }
}
