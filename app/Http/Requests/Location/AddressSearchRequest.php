<?php

namespace App\Http\Requests\Location;

use App\DataTransferObjects\Location\AddressSearchQuery;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação do autocomplete público de endereço.
 *
 * Mínimo de 3 caracteres: com menos, nenhum provedor devolve algo útil e cada letra viraria
 * consulta. A coordenada é opcional e vem em par — metade dela não enviesa nada.
 */
class AddressSearchRequest extends FormRequest
{
    public const DEFAULT_LIMIT = 5;

    public const MAX_LIMIT = 8;

    /** Rota pública: o controle de acesso é o throttle, não a autenticação. */
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
            'q' => ['required', 'string', 'min:3', 'max:200'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'limit' => ['nullable', 'integer', 'between:1,'.self::MAX_LIMIT],
        ];
    }

    public function toQuery(): AddressSearchQuery
    {
        return new AddressSearchQuery(
            text: trim((string) $this->validated('q')),
            latitude: $this->optionalFloat('latitude'),
            longitude: $this->optionalFloat('longitude'),
            limit: (int) ($this->validated('limit') ?? self::DEFAULT_LIMIT),
        );
    }

    private function optionalFloat(string $field): ?float
    {
        $value = $this->validated($field);

        return $value === null ? null : (float) $value;
    }
}
