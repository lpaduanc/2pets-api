<?php

namespace App\Http\Requests\BlockedTime;

use App\Http\Requests\Concerns\ResolvesTargetProfessional;
use App\Models\BlockedTime;
use App\Models\Location;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Bloqueia um intervalo da agenda do profissional (férias, almoço, compromisso) — o
 * `AvailabilityService` já lê `blocked_times` para excluir horários do slot público
 * (`GET /api/public/booking/availability`); este é o primeiro endpoint que ESCREVE nela.
 */
class StoreBlockedTimeRequest extends FormRequest
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

    private function validateLocationOwnership(Validator $validator): void
    {
        $locationId = $this->filled('location_id') ? (int) $this->input('location_id') : null;

        if ($locationId === null) {
            return;
        }

        $location = Location::find($locationId);

        $belongsToTarget = $location !== null && (
            (int) $location->professional_id === $this->targetProfessionalId()
            || ($this->organizationIdForTarget() !== null && $location->organization_id === $this->organizationIdForTarget())
        );

        if (! $belongsToTarget) {
            $validator->errors()->add('location_id', 'Este local não pertence ao profissional informado.');
        }
    }

    public function bodyParameters(): array
    {
        return [
            'professional_id' => ['description' => 'ID do profissional dono do bloqueio. Omitido: a própria pessoa autenticada.'],
            'location_id' => ['description' => 'Local ao qual este bloqueio se restringe. Omitido: bloqueia a agenda em qualquer local.'],
            'start_datetime' => ['description' => 'Início do bloqueio (data e hora, ISO 8601).'],
            'end_datetime' => ['description' => 'Fim do bloqueio. Deve ser maior que start_datetime.'],
            'reason' => ['description' => 'Motivo do bloqueio (ex.: "Férias", "Almoço").'],
        ];
    }
}
