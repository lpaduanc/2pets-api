<?php

namespace App\Http\Requests\Booking;

use App\DataTransferObjects\Booking\AvailabilityDaysQuery;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET /api/public/booking/availability-days` — Fase 2, item 5: dias do mês com ao menos um
 * horário livre, para o calendário do app desabilitar o resto.
 */
class ShowAvailabilityDaysRequest extends FormRequest
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
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'month' => ['required', 'date_format:Y-m'],
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

    public function toQuery(): AvailabilityDaysQuery
    {
        return new AvailabilityDaysQuery(
            professionalId: $this->filled('professional_id') ? (int) $this->input('professional_id') : null,
            organizationId: $this->filled('organization_id') ? (int) $this->input('organization_id') : null,
            locationId: $this->filled('location_id') ? (int) $this->input('location_id') : null,
            serviceId: $this->filled('service_id') ? (int) $this->input('service_id') : null,
            month: Carbon::createFromFormat('Y-m', $this->input('month'))->startOfMonth(),
        );
    }

    public function bodyParameters(): array
    {
        return [
            'professional_id' => ['description' => 'Profissional específico.'],
            'organization_id' => ['description' => 'Estabelecimento — obrigatório quando professional_id é omitido.'],
            'location_id' => ['description' => 'Local específico do estabelecimento.'],
            'service_id' => ['description' => 'Filtra a equipe (modo agregado) a quem executa este serviço.'],
            'month' => ['description' => 'Mês no formato YYYY-MM.'],
        ];
    }
}
