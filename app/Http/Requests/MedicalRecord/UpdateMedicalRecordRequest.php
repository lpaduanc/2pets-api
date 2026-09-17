<?php

namespace App\Http\Requests\MedicalRecord;

use App\Http\Requests\MedicalRecord\Concerns\HasStructuredConsultationRules;
use App\Models\MedicalRecord;
use App\Rules\ValidChiefComplaint;
use App\Rules\ValidPhysicalExam;
use App\Rules\ValidReportedPetData;
use App\Support\ReportedPetDataNormalizer;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMedicalRecordRequest extends FormRequest
{
    use HasStructuredConsultationRules;

    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    /**
     * Campo em branco de `reported_pet_data` vira chave AUSENTE antes de validar — ver
     * `ReportedPetDataNormalizer` para o porquê (rascunho padrão voltava 422, e vazio gravado
     * apaga cadastro do pet na promoção). Só mexe na chave quando ela veio no corpo: um `PUT`
     * que não menciona o bloco não pode zerá-lo.
     */
    protected function prepareForValidation(): void
    {
        if (! array_key_exists('reported_pet_data', $this->all())) {
            return;
        }

        $this->merge([
            'reported_pet_data' => (new ReportedPetDataNormalizer)->normalize($this->input('reported_pet_data')),
        ]);
    }

    public function rules(): array
    {
        return [
            ...$this->structuredConsultationRules(),
            // Contrato §1: este endpoint SÓ salva rascunho — enviar qualquer outro valor
            // (em particular "finalized") é rejeitado; finalizar é o endpoint dedicado.
            'status' => 'sometimes|in:draft',
            'chief_complaint' => ['nullable', 'string', new ValidChiefComplaint($this->petSpecies())],
            'chief_complaint_notes' => 'nullable|string|max:2000',
            'weight' => 'nullable|numeric|min:0|max:999.99',
            'temperature' => 'nullable|numeric|min:20|max:50',
            'heart_rate' => 'nullable|integer|min:0|max:500',
            'respiratory_rate' => 'nullable|integer|min:0|max:200',
            'physical_exam' => ['nullable', 'array', new ValidPhysicalExam],
            'capillary_refill_time' => ['nullable', 'string', 'in:'.implode(',', config('clinical-parameters.capillary_refill_time'))],
            'hydration_status' => ['nullable', 'string', 'in:'.implode(',', config('clinical-parameters.hydration_status'))],
            'body_condition_score' => ['nullable', 'integer', 'between:1,9'],
            'pain_score' => ['nullable', 'integer', 'between:0,4'],
            'subjective' => 'nullable|string|max:5000',
            'objective' => 'nullable|string|max:5000',
            'assessment' => 'nullable|string|max:5000',
            'plan' => 'nullable|string|max:5000',
            'symptoms' => 'nullable|array|max:50',
            'symptoms.*' => 'string|max:200',
            'diagnosis' => 'nullable|string|max:2000',
            'treatment_plan' => 'nullable|string|max:5000',
            // `prescriptions` NÃO é mais aceito aqui — ver StoreMedicalRecordRequest.
            'notes' => 'nullable|string|max:5000',
            'summary_for_tutor' => 'nullable|string|max:3000',
            'previous_record_id' => 'nullable|exists:medical_records,id',
            // Contrato §C: o que o TUTOR informou na consulta, à parte do cadastro — nunca
            // escreve em `pets` por aqui (só `POST /medical-records/{id}/apply-to-pet` faz isso).
            'reported_pet_data' => ['nullable', 'array', new ValidReportedPetData],
        ];
    }

    private function petSpecies(): ?string
    {
        return MedicalRecord::find($this->route('medical_record'))?->pet?->species;
    }
}
