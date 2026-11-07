<?php

namespace App\Http\Requests\Appointment;

use App\Enums\RecurrenceFrequency;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/**
 * Regras de `recurrence` e `resolutions` (PLANO 3.1), comuns à criação e à edição "este e os
 * seguintes". `until` e `count` são exclusivos (um dos dois); `count` ≤ 52; `weekdays` (0 =
 * domingo) só em semanal/quinzenal. `resolutions[].starts_at` é hora LOCAL de São Paulo, sem fuso
 * (`YYYY-MM-DDTHH:mm`), como a agenda guarda data e hora.
 */
trait RecurrenceRules
{
    /** @return array<string, mixed> */
    protected function recurrenceRules(): array
    {
        return [
            'recurrence' => ['nullable', 'array'],
            'recurrence.freq' => ['required_with:recurrence', Rule::enum(RecurrenceFrequency::class)],
            'recurrence.interval' => ['nullable', 'integer', 'min:1', 'max:12'],
            'recurrence.weekdays' => ['nullable', 'array', 'max:7'],
            'recurrence.weekdays.*' => ['integer', 'distinct', 'between:0,6'],
            'recurrence.until' => ['nullable', 'date_format:Y-m-d'],
            'recurrence.count' => ['nullable', 'integer', 'min:1', 'max:52'],
            'resolutions' => ['nullable', 'array'],
            'resolutions.*.occurrence_index' => ['required', 'integer', 'min:1', 'max:52'],
            'resolutions.*.action' => ['required', Rule::in(['skip', 'move'])],
            'resolutions.*.starts_at' => ['required_if:resolutions.*.action,move', 'nullable', 'date_format:Y-m-d\TH:i'],
        ];
    }

    protected function validateRecurrenceEnd(Validator $validator): void
    {
        $recurrence = $this->input('recurrence');

        if (! is_array($recurrence)) {
            return;
        }

        $hasUntil = ! empty($recurrence['until']);
        $hasCount = ! empty($recurrence['count']);

        if ($hasUntil === $hasCount) {
            $validator->errors()->add('recurrence', 'Informe o fim da repetição: uma data final OU a quantidade de vezes (até 52).');
        }

        $date = $this->input('appointment_date');

        if ($hasUntil && $date !== null && $recurrence['until'] < substr((string) $date, 0, 10)) {
            $validator->errors()->add('recurrence.until', 'A data final da repetição não pode ser antes do primeiro agendamento.');
        }
    }
}
