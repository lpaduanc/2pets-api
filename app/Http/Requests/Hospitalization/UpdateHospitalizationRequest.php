<?php

namespace App\Http\Requests\Hospitalization;

use App\Enums\HospitalizationRiskLevel;
use App\Enums\HospitalizationStatus;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Contrato docs/atendimento-veterinario/11-internacao-no-fluxo-de-faturamento.md §3/§4 e
 * docs/atendimento-veterinario/12-modulo-clinico-internacao.md §3.2/§5.2.
 *
 * `status` aceita `deceased` — alta, transferência e óbito fecham a estadia igualmente,
 * sem exceção de cobrança. `total_cost`/`daily_notes` não são aceitos (aposentados, doc 11
 * §4 e doc 12 §2): o valor cobrado vem sempre da `Invoice` do agendamento, e a nota
 * informal virou evolução diária estruturada.
 *
 * `discharge_summary` é exigido — não na coluna, aqui, em validação — quando `status`
 * transiciona para um estado que fecha a estadia (`discharged`/`transferred`/`deceased`):
 * a alta médica precisa de um resumo, mesmo que o restante do "documento de alta" seja só
 * agregação de dados que já existem (doc 12 §5.2).
 */
class UpdateHospitalizationRequest extends FormRequest
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
            'discharge_date' => ['nullable', 'date'],
            'estimated_discharge_date' => ['nullable', 'date'],
            'reason' => ['sometimes', 'required', 'string', 'max:255'],
            'status' => ['sometimes', 'required', Rule::enum(HospitalizationStatus::class)],
            'discharge_summary' => ['nullable', 'string'],
            'box_id' => ['nullable', 'integer', 'exists:hospitalization_boxes,id'],
            'risk_level' => ['nullable', Rule::in(HospitalizationRiskLevel::values())],
            'medications' => ['nullable', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->closesStay()) {
                return;
            }

            if (! $this->filled('discharge_summary')) {
                $validator->errors()->add('discharge_summary', 'Informe o resumo de alta ao encerrar a internação.');
            }
        });
    }

    private function closesStay(): bool
    {
        if (! $this->filled('status')) {
            return false;
        }

        return HospitalizationStatus::from($this->input('status'))->isClosed();
    }
}
