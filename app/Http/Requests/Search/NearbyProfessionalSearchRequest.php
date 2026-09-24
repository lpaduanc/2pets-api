<?php

namespace App\Http\Requests\Search;

use App\Http\Requests\Location\PostalCodeLookupRequest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação de `GET /api/public/nearby`: origem por coordenada OU por CEP.
 */
class NearbyProfessionalSearchRequest extends FormRequest
{
    /** Rota pública: o controle de acesso é o throttle nomeado `public-search`, não auth. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'latitude' => ['required_without:zip_code', 'required_with:longitude', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['required_without:zip_code', 'required_with:latitude', 'nullable', 'numeric', 'between:-180,180'],
            'zip_code' => ['nullable', 'string', PostalCodeLookupRequest::ZIP_CODE_RULE],
            'radius_km' => ['nullable', 'integer', 'min:1', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['zip_code.regex' => 'Informe um CEP válido, com 8 dígitos.'];
    }
}
