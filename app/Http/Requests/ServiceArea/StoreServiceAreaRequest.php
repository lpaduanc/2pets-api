<?php

namespace App\Http\Requests\ServiceArea;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Item 21 do backlog gap-simplesvet. Serve store e update — `sometimes` no update, mesmo
 * espírito de `StoreProductGroupRequest`.
 */
class StoreServiceAreaRequest extends FormRequest
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
            'name' => [$required, 'string', 'max:100'],
            'color' => ['nullable', 'string', 'size:7', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'active' => ['nullable', 'boolean'],
        ];
    }
}
