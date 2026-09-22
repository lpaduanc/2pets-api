<?php

namespace App\Http\Requests\BlockedTime;

use App\Models\BlockedTime;
use App\Models\Location;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateBlockedTimeRequest extends FormRequest
{
    private ?BlockedTime $resolvedBlockedTime = null;

    public function authorize(): bool
    {
        return Gate::forUser($this->user())->allows('manage', $this->blockedTime());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'start_datetime' => ['required', 'date'],
            'end_datetime' => ['required', 'date', 'after:start_datetime'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->validateLocationOwnership($validator);
        });
    }

    public function blockedTime(): BlockedTime
    {
        return $this->resolvedBlockedTime ??= BlockedTime::findOrFail((int) $this->route('id'));
    }

    private function validateLocationOwnership(Validator $validator): void
    {
        $locationId = $this->has('location_id')
            ? ($this->filled('location_id') ? (int) $this->input('location_id') : null)
            : $this->blockedTime()->location_id;

        if ($locationId === null) {
            return;
        }

        $location = Location::find($locationId);
        $professionalId = (int) $this->blockedTime()->professional_id;
        $organizationId = $this->blockedTime()->organization_id;

        $belongsToTarget = $location !== null && (
            (int) $location->professional_id === $professionalId
            || ($organizationId !== null && $location->organization_id === $organizationId)
        );

        if (! $belongsToTarget) {
            $validator->errors()->add('location_id', 'Este local não pertence ao profissional informado.');
        }
    }

    public function bodyParameters(): array
    {
        return [
            'location_id' => ['description' => 'Local ao qual este bloqueio se restringe. Omitido: mantém o local atual.'],
            'start_datetime' => ['description' => 'Início do bloqueio (data e hora, ISO 8601).'],
            'end_datetime' => ['description' => 'Fim do bloqueio. Deve ser maior que start_datetime.'],
            'reason' => ['description' => 'Motivo do bloqueio (ex.: "Férias", "Almoço").'],
        ];
    }
}
