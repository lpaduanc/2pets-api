<?php

namespace App\Http\Requests\Hospitalization;

use App\Enums\HospitalizationRiskLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Contrato docs/atendimento-veterinario/11-internacao-no-fluxo-de-faturamento.md §1/§2/§6 e
 * docs/atendimento-veterinario/12-modulo-clinico-internacao.md §3.
 *
 * Admissão. `status` não é aceito aqui — toda internação nasce `active`
 * (`HospitalizationService::admit`). `total_cost` também não é aceito — o valor cobrado
 * vem sempre da `Invoice` do agendamento (doc 11 §4). `daily_notes` foi aposentado (doc 12
 * §2) — deixou de ser aceito, mesmo se enviado no payload.
 */
class StoreHospitalizationRequest extends FormRequest
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
            'pet_id' => ['required', 'integer', 'exists:pets,id'],
            'admission_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:255'],

            // Contrato doc 11 §2.1/§6: item(ns) já sabido(s) no momento da admissão (ex.: a
            // primeira diária) — opcional, o resto entra depois via `/charges`.
            'services' => ['nullable', 'array'],
            'services.*.service_id' => ['required', 'integer', 'exists:services,id'],
            'services.*.quantity' => ['nullable', 'numeric', 'min:0.01'],
            'services.*.unit_price' => ['nullable', 'numeric', 'min:0'],

            // Contrato doc 12 §3.1: vínculo com o prontuário (de OUTRO atendimento) que
            // indicou internar. Nullable — walk-in de emergência pode não ter um.
            'indicating_medical_record_id' => ['nullable', 'integer', 'exists:medical_records,id'],
            'estimated_discharge_date' => ['nullable', 'date'],

            // Contrato docs/gap-simplesvet/specs/12-internacao-mapa-execucao-spec.md §1/§2:
            // box e risco são sempre opcionais (regras de negócio 1/2) — a checagem de "box
            // já ocupado" é regra de aplicação em `HospitalizationBoxOccupancyGuard`, não
            // expressável aqui.
            'box_id' => ['nullable', 'integer', 'exists:hospitalization_boxes,id'],
            'risk_level' => ['nullable', Rule::in(HospitalizationRiskLevel::values())],

            'medications' => ['nullable', 'array'],
        ];
    }
}
