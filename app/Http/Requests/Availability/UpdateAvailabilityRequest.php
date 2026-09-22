<?php

namespace App\Http\Requests\Availability;

use App\DataTransferObjects\AvailabilityWindow;
use App\Models\Availability;
use App\Models\Location;
use App\Services\Booking\AvailabilityOverlapChecker;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Substitui por completo UMA janela de disponibilidade existente (semântica de `PUT`) —
 * `professional_id` não é reatribuível aqui; dono da agenda não muda por edição de horário.
 */
class UpdateAvailabilityRequest extends FormRequest
{
    private ?Availability $resolvedAvailability = null;

    public function authorize(): bool
    {
        return Gate::forUser($this->user())->allows('manage', $this->availability());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
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

    /**
     * Route model binding manual (a rota usa `{id}`, não `{availability}`) — resolvido uma
     * única vez e reaproveitado pelo controller, para não repetir a consulta.
     */
    public function availability(): Availability
    {
        return $this->resolvedAvailability ??= Availability::findOrFail((int) $this->route('id'));
    }

    private function resolvedLocationId(): ?int
    {
        return $this->has('location_id')
            ? ($this->filled('location_id') ? (int) $this->input('location_id') : null)
            : $this->availability()->location_id;
    }

    private function validateLocationOwnership(Validator $validator): void
    {
        $locationId = $this->resolvedLocationId();

        if ($locationId === null) {
            return;
        }

        $location = Location::find($locationId);
        $professionalId = (int) $this->availability()->professional_id;
        $organizationId = $this->availability()->organization_id;

        $belongsToTarget = $location !== null && (
            (int) $location->professional_id === $professionalId
            || ($organizationId !== null && $location->organization_id === $organizationId)
        );

        if (! $belongsToTarget) {
            $validator->errors()->add('location_id', 'Este local não pertence ao profissional informado.');
        }
    }

    private function validateNoOverlap(Validator $validator): void
    {
        $window = AvailabilityWindow::fromArray([
            'professional_id' => (int) $this->availability()->professional_id,
            'location_id' => $this->resolvedLocationId(),
            'day_of_week' => (int) $this->input('day_of_week'),
            'start_time' => (string) $this->input('start_time'),
            'end_time' => (string) $this->input('end_time'),
        ]);

        if (app(AvailabilityOverlapChecker::class)->overlaps($window, $this->availability()->id)) {
            $validator->errors()->add(
                'start_time',
                'Já existe uma janela cadastrada que se sobrepõe a este dia, horário e local.'
            );
        }
    }

    public function bodyParameters(): array
    {
        return [
            'location_id' => ['description' => 'Local (clínica/consultório) desta janela. Omitido: mantém o local atual.'],
            'day_of_week' => ['description' => 'Dia da semana: 0 (domingo) a 6 (sábado).'],
            'start_time' => ['description' => 'Horário de início, formato HH:MM.'],
            'end_time' => ['description' => 'Horário de término, formato HH:MM. Deve ser maior que start_time.'],
            'slot_duration' => ['description' => 'Duração de cada horário disponível, em minutos.'],
            'buffer_time' => ['description' => 'Intervalo entre atendimentos, em minutos.'],
            'is_active' => ['description' => 'Se esta janela está ativa.'],
        ];
    }
}
