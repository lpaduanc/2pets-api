<?php

namespace App\Http\Requests\Registration\Draft;

use App\DataTransferObjects\Cnpj;
use App\DataTransferObjects\Cpf;
use App\Http\Requests\Concerns\FormatsDuplicateFieldErrors;
use App\Http\Requests\Registration\Draft\Concerns\HasOptionalAddressRules;
use App\Rules\ValidCpf;
use App\Rules\ValidCrmv;
use App\Services\CrmvValidationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /register/draft/professional` — autosave progressivo do cadastro de vet
 * volante/clínica/laboratório/petshop/pet_hotel/grooming/training. Nenhum campo é `required`
 * (o usuário pode estar em qualquer etapa do formulário); a segmentação por
 * `professional_type` (o que cada tipo pode preencher) continua sendo aplicada só na
 * conclusão de cadastro (`CompleteVetRegistrationRequest`/`CompleteGenericProfessionalRegistrationRequest`
 * + `HasProfessionalCapabilityRules`) — o rascunho aceita qualquer um dos campos de qualquer
 * tipo, exatamente como já se comportava antes desta classe existir.
 */
class SaveProfessionalDraftRequest extends FormRequest
{
    use FormatsDuplicateFieldErrors, HasOptionalAddressRules;

    public function authorize(): bool
    {
        return true; // Autorização é feita pelo middleware auth:sanctum da rota.
    }

    /** @return list<string> */
    protected function duplicateFields(): array
    {
        return ['cpf', 'cnpj', 'crmv'];
    }

    /**
     * Documento sempre trafega e é gravado limpo (só dígitos), mesma regra de projeto de
     * `DocumentNumber`. CRMV, além disso, é normalizado para o formato canônico
     * (`CRMV/UF 12345`) ANTES da validação — o mesmo formato que
     * `RegistrationCompletionService::vetProfessionalData()` grava na conclusão. Sem isto, o
     * rascunho salvaria o valor cru digitado, o `Rule::unique` nunca bateria contra a coluna
     * já formatada de outro profissional, e o valor ficaria inconsistente entre o autosave e
     * a conclusão de cadastro.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('cpf')) {
            $this->merge(['cpf' => Cpf::stripMask($this->input('cpf'))]);
        }

        if ($this->has('cnpj')) {
            $this->merge(['cnpj' => Cnpj::stripMask($this->input('cnpj'))]);
        }

        if ($this->has('crmv') && $this->has('crmv_state')) {
            $this->merge([
                'crmv' => app(CrmvValidationService::class)->format(
                    (string) $this->input('crmv'),
                    (string) $this->input('crmv_state'),
                ),
            ]);
        }

        if ($this->has('technical_responsible_crmv') && $this->has('technical_responsible_crmv_state')) {
            $this->merge([
                'technical_responsible_crmv' => app(CrmvValidationService::class)->format(
                    (string) $this->input('technical_responsible_crmv'),
                    (string) $this->input('technical_responsible_crmv_state'),
                ),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            ...$this->optionalAddressRules(),
            ...$this->identityRules(),
            ...$this->professionalDetailsRules(),
        ];
    }

    /** @return array<string, list<mixed>> */
    private function identityRules(): array
    {
        return [
            'cpf' => [
                'sometimes', 'nullable', 'digits:'.Cpf::DIGIT_COUNT, app(ValidCpf::class),
                Rule::unique('users', 'cpf')->ignore($this->user()?->id)->withoutTrashed(),
            ],
            'professional_phone' => ['sometimes', 'nullable', 'string'],
            'cnpj' => [
                'sometimes', 'nullable', 'digits:14',
                Rule::unique('professionals', 'cnpj')->ignore($this->user()?->id, 'user_id')->withoutTrashed(),
            ],
            'crmv' => [
                'sometimes', 'nullable', 'string', app(ValidCrmv::class),
                Rule::unique('professionals', 'crmv')->ignore($this->user()?->id, 'user_id')->withoutTrashed(),
            ],
            'crmv_state' => ['sometimes', 'nullable', 'string', 'size:2'],
        ];
    }

    /** @return array<string, list<mixed>> */
    private function professionalDetailsRules(): array
    {
        return [
            'business_name' => ['sometimes', 'nullable', 'string'],
            'website' => ['sometimes', 'nullable', 'string'],
            'university' => ['sometimes', 'nullable', 'string'],
            'graduation_year' => ['sometimes', 'nullable', 'integer', 'min:1950', 'max:'.date('Y')],
            'experience_years' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'specialties' => ['sometimes', 'nullable', 'array'],
            'courses' => ['sometimes', 'nullable', 'array'],
            'service_radius_km' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'opening_hours' => ['sometimes', 'nullable', 'string'],
            'closing_hours' => ['sometimes', 'nullable', 'string'],
            'working_days' => ['sometimes', 'nullable', 'array'],
            'description' => ['sometimes', 'nullable', 'string'],
            'technical_responsible_name' => ['sometimes', 'nullable', 'string'],
            'technical_responsible_crmv' => [
                'sometimes', 'nullable', 'string',
                app(ValidCrmv::class, ['stateField' => 'technical_responsible_crmv_state']),
            ],
            'technical_responsible_crmv_state' => ['sometimes', 'nullable', 'string', 'size:2'],
            'services_offered' => ['sometimes', 'nullable', 'array'],
            'products_sold' => ['sometimes', 'nullable', 'array'],
            'equipment' => ['sometimes', 'nullable', 'array'],
            'certifications' => ['sometimes', 'nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            ...$this->optionalAddressMessages(),
            'cpf.digits' => 'O CPF deve conter 11 dígitos.',
            'cpf.unique' => 'Este CPF já está cadastrado.',
            'cnpj.digits' => 'O CNPJ deve conter 14 dígitos.',
            'cnpj.unique' => 'Este CNPJ já está cadastrado.',
            'crmv.unique' => 'Este CRMV já está cadastrado.',
            'crmv_state.size' => 'A UF do CRMV deve ter 2 letras.',
            'graduation_year.min' => 'Ano de formatura inválido.',
            'graduation_year.max' => 'O ano de formatura não pode ser no futuro.',
        ];
    }
}
