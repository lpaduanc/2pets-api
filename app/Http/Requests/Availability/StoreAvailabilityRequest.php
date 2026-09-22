<?php

namespace App\Http\Requests\Availability;

use App\DataTransferObjects\AvailabilityWindow;
use App\Http\Requests\Concerns\ResolvesTargetProfessional;
use App\Models\Availability;
use App\Models\Location;
use App\Services\Booking\AvailabilityOverlapChecker;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Cria UMA janela de disponibilidade semanal (ex.: "segunda, 08:00–18:00, slot de 30min").
 * Para substituir a semana inteira de uma vez, ver `ReplaceWeeklyAvailabilityRequest`.
 */
class StoreAvailabilityRequest extends FormRequest
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
            'day_of_week' => ['required', 'integer', 'between:0,6'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'slot_duration' => ['required', 'integer', 'min:1'],
            'buffer_time' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->validateLocationOwnership($validator);
            $this->validateNoOverlap($validator);
        });
    }

    private function validateLocationOwnership(Validator $validator): void
    {
        $locationId = $this->filled('location_id') ? (int) $this->input('location_id') : null;

        if ($locationId === null) {
            return;
        }

        $location = Location::find($locationId);

        if ($location === null || ! $this->locationBelongsToTarget($location)) {
            $validator->errors()->add('location_id', 'Este local não pertence ao profissional informado.');
        }
    }

    private function locationBelongsToTarget(Location $location): bool
    {
        if ((int) $location->professional_id === $this->targetProfessionalId()) {
            return true;
        }

        $organizationId = $this->organizationIdForTarget();

        return $organizationId !== null && $location->organization_id === $organizationId;
    }

    private function validateNoOverlap(Validator $validator): void
    {
        $window = AvailabilityWindow::fromArray([
            'professional_id' => $this->targetProfessionalId(),
            'location_id' => $this->filled('location_id') ? (int) $this->input('location_id') : null,
            'day_of_week' => (int) $this->input('day_of_week'),
            'start_time' => (string) $this->input('start_time'),
            'end_time' => (string) $this->input('end_time'),
        ]);

        if (app(AvailabilityOverlapChecker::class)->overlaps($window)) {
            $validator->errors()->add(
                'start_time',
                'Já existe uma janela cadastrada que se sobrepõe a este dia, horário e local.'
            );
        }
    }

    public function bodyParameters(): array
    {
        return [
            'professional_id' => ['description' => 'ID do profissional dono da agenda. Omitido: a própria pessoa autenticada. Só aceito quando o autenticado é dono da organização do profissional informado.'],
            'location_id' => ['description' => 'Local (clínica/consultório) desta janela. Omitido: agenda sem local fixo.'],
            'day_of_week' => ['description' => 'Dia da semana: 0 (domingo) a 6 (sábado).'],
            'start_time' => ['description' => 'Horário de início, formato HH:MM.'],
            'end_time' => ['description' => 'Horário de término, formato HH:MM. Deve ser maior que start_time.'],
            'slot_duration' => ['description' => 'Duração de cada horário disponível, em minutos.'],
            'buffer_time' => ['description' => 'Intervalo entre atendimentos, em minutos. Default: 0.'],
            'is_active' => ['description' => 'Se esta janela está ativa. Default: true.'],
        ];
    }
}
