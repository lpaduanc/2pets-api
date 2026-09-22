<?php

namespace App\Http\Requests\Availability;

use App\Http\Requests\Concerns\ResolvesTargetProfessional;
use App\Models\Availability;
use App\Models\Location;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;

/**
 * Substitui a grade semanal INTEIRA de um profissional (e local) de uma vez — a forma como
 * o app edita a agenda (grade com uma linha por dia, não item a item). Apaga toda janela
 * existente para `(professional_id, location_id)` e recria a partir de `windows`.
 */
class ReplaceWeeklyAvailabilityRequest extends FormRequest
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
            'windows' => ['present', 'array'],
            'windows.*.day_of_week' => ['required', 'integer', 'between:0,6'],
            'windows.*.start_time' => ['required', 'date_format:H:i'],
            'windows.*.end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'windows.*.slot_duration' => ['required', 'integer', 'min:1'],
            'windows.*.buffer_time' => ['nullable', 'integer', 'min:0'],
            'windows.*.is_active' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->validateLocationOwnership($validator);
            $this->validateNoInternalOverlap($validator);
        });
    }

    /**
     * @return list<array{day_of_week: int, start_time: string, end_time: string, slot_duration: int, buffer_time: int, is_active: bool}>
     */
    public function windows(): array
    {
        return collect($this->input('windows', []))
            ->map(fn (array $window): array => [
                'day_of_week' => (int) $window['day_of_week'],
                'start_time' => (string) $window['start_time'],
                'end_time' => (string) $window['end_time'],
                'slot_duration' => (int) $window['slot_duration'],
                'buffer_time' => (int) ($window['buffer_time'] ?? 0),
                'is_active' => (bool) ($window['is_active'] ?? true),
            ])
            ->all();
    }

    public function locationId(): ?int
    {
        return $this->filled('location_id') ? (int) $this->input('location_id') : null;
    }

    private function validateLocationOwnership(Validator $validator): void
    {
        $locationId = $this->locationId();

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

    private function validateNoInternalOverlap(Validator $validator): void
    {
        Collection::make($this->windows())
            ->groupBy('day_of_week')
            ->each(function (Collection $windowsForDay, int $dayOfWeek) use ($validator): void {
                $this->validateDayHasNoOverlap($windowsForDay, $dayOfWeek, $validator);
            });
    }

    private function validateDayHasNoOverlap(Collection $windowsForDay, int $dayOfWeek, Validator $validator): void
    {
        $sorted = $windowsForDay->sortBy('start_time')->values();

        foreach ($sorted as $index => $window) {
            $previous = $sorted->get($index - 1);

            if ($index > 0 && $window['start_time'] < $previous['end_time']) {
                $validator->errors()->add(
                    "windows.day_{$dayOfWeek}",
                    "Há janelas sobrepostas no dia da semana {$dayOfWeek}."
                );

                return;
            }
        }
    }

    public function bodyParameters(): array
    {
        return [
            'professional_id' => ['description' => 'ID do profissional dono da agenda. Omitido: a própria pessoa autenticada.'],
            'location_id' => ['description' => 'Local ao qual esta grade semanal pertence. Omitido: agenda sem local fixo.'],
            'windows' => ['description' => 'Lista de janelas da semana. Substitui por completo a grade anterior para este profissional+local — pode vir vazia para limpar a agenda.'],
        ];
    }
}
