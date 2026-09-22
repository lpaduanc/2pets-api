<?php

namespace App\Http\Requests\Agenda;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET professional/agenda/day?date=&location_id=&area_id=` — item 21 do backlog
 * gap-simplesvet. Qualquer conta autenticada do domínio `professional/` pode ler a própria
 * grade (ou a da equipe, se tiver organização); não há dado sensível novo aqui além do que
 * `professional/appointments` já expõe.
 */
class DayAgendaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'area_id' => ['nullable', 'integer', 'exists:service_areas,id'],
        ];
    }

    public function locationId(): ?int
    {
        return $this->filled('location_id') ? (int) $this->input('location_id') : null;
    }

    public function areaId(): ?int
    {
        return $this->filled('area_id') ? (int) $this->input('area_id') : null;
    }
}
