<?php

namespace App\Http\Requests\Location;

use App\Enums\Location\SearchLocationSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PUT /api/me/search-location` — o próprio usuário grava a localização que confirmou.
 * Não há recurso de terceiro envolvido: a autorização é estar autenticado.
 */
class UpdateSearchLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'source' => ['required', Rule::enum(SearchLocationSource::class)],
            'label' => ['nullable', 'string', 'max:255'],
            'zip_code' => ['nullable', 'string', PostalCodeLookupRequest::ZIP_CODE_RULE],
            'neighborhood' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'size:2'],
        ];
    }
}
