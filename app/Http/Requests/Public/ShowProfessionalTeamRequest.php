<?php

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET /api/public/professionals/{id}/team` — endpoint público (sem `auth:sanctum`, mesmo
 * padrão de `GET /api/public/professionals/{id}`), então `authorize()` só confirma que a
 * rota é alcançável por qualquer visitante.
 */
class ShowProfessionalTeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
        ];
    }

    public function serviceId(): ?int
    {
        return $this->filled('service_id') ? (int) $this->input('service_id') : null;
    }
}
