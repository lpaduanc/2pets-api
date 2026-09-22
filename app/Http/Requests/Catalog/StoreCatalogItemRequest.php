<?php

namespace App\Http\Requests\Catalog;

use App\Support\Catalog\CatalogTypeRegistry;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Serve store e update dos 6 catálogos configuráveis do item 23 (backlog gap-simplesvet) —
 * mesmo espírito de `StoreProductGroupRequest`: `sometimes` no update, `required` no create.
 */
class StoreCatalogItemRequest extends FormRequest
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
        $isCreate = $this->isMethod('POST');
        $required = $isCreate ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:150'],
            'active' => ['nullable', 'boolean'],
            ...CatalogTypeRegistry::extraRulesFor((string) $this->route('type'), $isCreate),
        ];
    }
}
