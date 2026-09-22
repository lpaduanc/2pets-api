<?php

namespace App\Http\Requests\Booking;

use App\DataTransferObjects\Booking\AvailabilityContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET /api/public/booking/availability` — Fase 2 do fluxo de agendamento: `professional_id`
 * deixou de ser o único jeito de pedir agenda. Com `organization_id` (e sem
 * `professional_id`) a resposta é o modo agregado ("qualquer profissional disponível" —
 * `AvailabilityAggregationService`), mantendo 100% compatível a chamada antiga
 * (só `professional_id` + `date` + `service_id`).
 */
class ShowAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'professional_id' => ['nullable', 'integer', 'exists:users,id'],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'date' => ['required', 'date', 'after_or_equal:today'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('professional_id') && ! $this->filled('organization_id')) {
                $validator->errors()->add('professional_id', 'Informe professional_id ou organization_id.');
            }
        });
    }

    public function isAggregated(): bool
    {
        return ! $this->filled('professional_id');
    }

    public function professionalId(): ?int
    {
        return $this->filled('professional_id') ? (int) $this->input('professional_id') : null;
    }

    public function organizationId(): ?int
    {
        return $this->filled('organization_id') ? (int) $this->input('organization_id') : null;
    }

    public function locationId(): ?int
    {
        return $this->filled('location_id') ? (int) $this->input('location_id') : null;
    }

    public function serviceId(): ?int
    {
        return $this->filled('service_id') ? (int) $this->input('service_id') : null;
    }

    public function context(): AvailabilityContext
    {
        return new AvailabilityContext($this->organizationId(), $this->locationId());
    }

    public function bodyParameters(): array
    {
        return [
            'professional_id' => ['description' => 'Profissional específico. Omitido + organization_id: modo agregado (qualquer profissional da equipe).'],
            'organization_id' => ['description' => 'Estabelecimento (clínica/petshop/etc.). Obrigatório quando professional_id é omitido.'],
            'location_id' => ['description' => 'Local específico do estabelecimento, quando houver mais de um.'],
            'date' => ['description' => 'Data (YYYY-MM-DD), hoje ou futura.'],
            'service_id' => ['description' => 'Serviço desejado — define a duração do slot e, no modo agregado, filtra quem da equipe executa esse serviço.'],
        ];
    }
}
