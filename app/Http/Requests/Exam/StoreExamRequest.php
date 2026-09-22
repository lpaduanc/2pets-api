<?php

namespace App\Http\Requests\Exam;

use App\Enums\HospitalizationStatus;
use App\Models\Hospitalization;
use App\Rules\ScopedExamTypeExists;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Contrato docs/atendimento-veterinario/11-internacao-no-fluxo-de-faturamento.md §2.2:
 * `service_id`/`unit_price` só passam a ser exigidos quando o pet está com internação
 * ativa — é o único caso em que `POST /exams` também lança uma linha de cobrança
 * (`HospitalizationExamService`). Fora de internação, o comportamento é o de sempre: só os
 * dados clínicos do exame.
 */
class StoreExamRequest extends FormRequest
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
            'exam_type' => ['required', 'string'],
            'exam_name' => ['required', 'string', 'max:255'],
            'exam_type_id' => ['nullable', 'integer', new ScopedExamTypeExists($this->user())],
            'exam_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
            'appointment_id' => ['nullable', 'exists:appointments,id'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->filled('service_id') || $this->filled('unit_price')) {
                return;
            }

            if (! $this->petHasActiveHospitalization()) {
                return;
            }

            $validator->errors()->add(
                'unit_price',
                'Pet internado: informe o serviço do catálogo ou o valor da cobrança deste exame.'
            );
        });
    }

    private function petHasActiveHospitalization(): bool
    {
        return Hospitalization::where('pet_id', (int) $this->input('pet_id'))
            ->where('status', HospitalizationStatus::ACTIVE->value)
            ->exists();
    }
}
