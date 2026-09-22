<?php

namespace App\Http\Requests\Availability;

use App\Http\Requests\Concerns\ResolvesTargetProfessional;
use App\Models\Availability;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Lista a agenda semanal de um profissional. `professional_id` ausente: a própria pessoa
 * autenticada; presente: só quando quem pede é dono da organização daquele profissional
 * (mesma régua de escrita — ver `AvailabilityPolicy::manageFor()`).
 */
class ListAvailabilityRequest extends FormRequest
{
    use ResolvesTargetProfessional;

    public function authorize(): bool
    {
        return $this->authorizeManagingTarget(Availability::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'professional_id' => ['nullable', 'integer', 'exists:users,id'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
        ];
    }

    public function locationId(): ?int
    {
        return $this->filled('location_id') ? (int) $this->input('location_id') : null;
    }
}
