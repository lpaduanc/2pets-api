<?php

namespace App\Http\Requests\Location;

use Illuminate\Foundation\Http\FormRequest;

/**
 * CEP da rota `GET /public/postal-code/{zipCode}`. Validado aqui (e não por `->where()` na
 * rota) para que CEP mal formado seja 422 no campo `zip_code` — o mesmo erro que o frontend
 * já mostra para CEP inexistente — em vez de um 404 genérico.
 */
class PostalCodeLookupRequest extends FormRequest
{
    public const ZIP_CODE_RULE = 'regex:/^\d{5}-?\d{3}$/';

    /** Rota pública: o controle de acesso é o throttle `postal-code`, não a autenticação. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['zip_code' => trim((string) $this->route('zipCode'))]);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['zip_code' => ['required', 'string', self::ZIP_CODE_RULE]];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['zip_code.regex' => 'Informe um CEP válido, com 8 dígitos.'];
    }

    public function zipCode(): string
    {
        return (string) $this->validated('zip_code');
    }
}
