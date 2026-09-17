<?php

namespace App\Http\Requests\Hospitalization;

use App\Enums\ServiceCategory;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Contrato docs/atendimento-veterinario/11-internacao-no-fluxo-de-faturamento.md §2.2 —
 * abre um ato clínico Grupo A (ex.: cirurgia) durante uma internação ativa, pendurado no
 * MESMO `appointment_id` da internação. `category` restringe à lista estrutural do Grupo A
 * (`ServiceCategory::groupA()`) — a checagem dinâmica de "nutrição/comportamento exige
 * veterinário" (`isVeterinarianGated()`) depende de QUEM está autenticado, então vive no
 * `HospitalizationClinicalActService`, não aqui.
 *
 * `service_id`/`unit_price`: mesma regra de `StoreExamRequest` para exame durante
 * internação — o ato sempre lança uma linha de cobrança na mesma transação, então exige
 * ao menos um dos dois (serviço do catálogo ou valor avulso).
 */
class StoreHospitalizationClinicalActRequest extends FormRequest
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
            'category' => ['required', 'string', Rule::in($this->groupACategoryValues())],
            'reason' => ['nullable', 'string', 'max:255'],
            'record_date' => ['nullable', 'date'],
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

            $validator->errors()->add(
                'unit_price',
                'Informe o serviço do catálogo ou o valor da cobrança deste ato clínico.'
            );
        });
    }

    /**
     * @return list<string>
     */
    private function groupACategoryValues(): array
    {
        return array_map(
            static fn (ServiceCategory $category): string => $category->value,
            ServiceCategory::groupA(),
        );
    }

    public function bodyParameters(): array
    {
        return [
            'category' => ['description' => 'Categoria do ato clínico Grupo A (ex.: surgery, consultation, emergency, dental, rehabilitation, nutrition, behavioral).'],
            'reason' => ['description' => 'Descrição do ato (ex.: "Ovariohisterectomia"). Vira também a descrição da linha de cobrança.'],
            'record_date' => ['description' => 'Data do ato (YYYY-MM-DD). Default: hoje.'],
            'service_id' => ['description' => 'Serviço do catálogo do profissional para precificar o ato.'],
            'unit_price' => ['description' => 'Valor avulso do ato, quando não há serviço de catálogo correspondente.'],
        ];
    }
}
