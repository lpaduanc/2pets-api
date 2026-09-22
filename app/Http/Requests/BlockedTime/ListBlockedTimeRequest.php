<?php

namespace App\Http\Requests\BlockedTime;

use App\Http\Requests\Concerns\ResolvesTargetProfessional;
use App\Models\BlockedTime;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Lista os bloqueios de agenda de um profissional. Mesma régua de acesso de
 * `ListAvailabilityRequest` — ver `BlockedTimePolicy::manageFor()`.
 */
class ListBlockedTimeRequest extends FormRequest
{
    use ResolvesTargetProfessional;

    public function authorize(): bool
    {
        return $this->authorizeManagingTarget(BlockedTime::class);
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
