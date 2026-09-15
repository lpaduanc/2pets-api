<?php

namespace App\Http\Requests\Location;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação das coordenadas do reverse geocoding público.
 *
 * Mesmas regras dos demais endpoints geográficos (`between:-90,90` / `between:-180,180`) —
 * coordenada fora do planeta nunca chega a gastar cota paga do Google.
 */
class ReverseGeocodeRequest extends FormRequest
{
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
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ];
    }

    public function latitude(): float
    {
        return (float) $this->validated('latitude');
    }

    public function longitude(): float
    {
        return (float) $this->validated('longitude');
    }
}
