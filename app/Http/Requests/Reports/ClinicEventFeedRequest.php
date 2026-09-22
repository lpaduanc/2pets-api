<?php

namespace App\Http\Requests\Reports;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET reports/clinic-events` — contrato `docs/gap-simplesvet/specs/25-paineis-operacionais-spec.md`.
 */
class ClinicEventFeedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'event_type' => ['sometimes', 'array'],
            'event_type.*' => ['string', 'in:appointment,vaccination,exam,document'],
            'client_id' => ['sometimes', 'integer'],
            'species' => ['sometimes', 'string'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ];
    }
}
