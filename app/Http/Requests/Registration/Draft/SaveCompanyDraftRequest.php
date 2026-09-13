<?php

namespace App\Http\Requests\Registration\Draft;

use App\DataTransferObjects\Cnpj;
use App\DataTransferObjects\Cpf;
use App\Enums\BudgetRange;
use App\Enums\EstimatedPetOwnersRange;
use App\Enums\IndustrySector;
use App\Enums\PreferredCommunicationChannel;
use App\Http\Requests\Concerns\FormatsDuplicateFieldErrors;
use App\Http\Requests\Registration\Draft\Concerns\HasOptionalAddressRules;
use App\Rules\ValidCpf;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /register/draft/company` — autosave progressivo do cadastro de empresa parceira
 * (Clube de Vantagens). Nenhum campo é `required`, mesma razão do `SaveProfessionalDraftRequest`.
 */
class SaveCompanyDraftRequest extends FormRequest
{
    use FormatsDuplicateFieldErrors, HasOptionalAddressRules;

    public function authorize(): bool
    {
        return true; // Autorização é feita pelo middleware auth:sanctum da rota.
    }

    /** @return list<string> */
    protected function duplicateFields(): array
    {
        return ['cnpj'];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('cnpj')) {
            $this->merge(['cnpj' => Cnpj::stripMask($this->input('cnpj'))]);
        }

        if ($this->has('legal_representative_cpf')) {
            $this->merge(['legal_representative_cpf' => Cpf::stripMask($this->input('legal_representative_cpf'))]);
        }
    }

    public function rules(): array
    {
        return [
            ...$this->optionalAddressRules(),
            ...$this->companyProfileRules(),
            ...$this->legalRepresentativeRules(),
            ...$this->qualificationRules(),
        ];
    }

    /** @return array<string, list<mixed>> */
    private function companyProfileRules(): array
    {
        return [
            'company_name' => ['sometimes', 'nullable', 'string'],
            'cnpj' => [
                'sometimes', 'nullable', 'digits:'.Cnpj::DIGIT_COUNT,
                Rule::unique('companies', 'cnpj')->ignore($this->user()?->id, 'user_id'),
            ],
            'contact_name' => ['sometimes', 'nullable', 'string'],
            'contact_position' => ['sometimes', 'nullable', 'string'],
            'phone' => ['sometimes', 'nullable', 'string'],
            'website' => ['sometimes', 'nullable', 'string'],
            'employee_count' => ['sometimes', 'nullable', 'string'],
            'benefit_type' => ['sometimes', 'nullable', 'string'],
            'additional_notes' => ['sometimes', 'nullable', 'string'],
        ];
    }

    /**
     * CPF do representante legal: só dígito verificador, DE PROPÓSITO sem `Rule::unique` —
     * mesma decisão de `CompleteCompanyRegistrationRequest` (ver `correcao-cadastro-onda2-2026-09-13.md`).
     *
     * @return array<string, list<mixed>>
     */
    private function legalRepresentativeRules(): array
    {
        return [
            'legal_representative_name' => ['sometimes', 'nullable', 'string'],
            'legal_representative_cpf' => ['sometimes', 'nullable', 'digits:'.Cpf::DIGIT_COUNT, app(ValidCpf::class)],
            'legal_representative_birth_date' => ['sometimes', 'nullable', 'date'],
            'legal_representative_phone' => ['sometimes', 'nullable', 'string'],
        ];
    }

    /** @return array<string, list<mixed>> */
    private function qualificationRules(): array
    {
        return [
            'industry_sector' => ['sometimes', 'nullable', Rule::enum(IndustrySector::class)],
            'has_pet_policy' => ['sometimes', 'nullable', 'boolean'],
            'estimated_pet_owners' => ['sometimes', 'nullable', Rule::enum(EstimatedPetOwnersRange::class)],
            'preferred_communication' => ['sometimes', 'nullable', Rule::enum(PreferredCommunicationChannel::class)],
            'budget_range' => ['sometimes', 'nullable', Rule::enum(BudgetRange::class)],
            'start_date_preference' => ['sometimes', 'nullable', 'date'],
            'interested_services' => ['sometimes', 'nullable', 'array'],
            'interested_services.*' => ['string'],
        ];
    }

    public function messages(): array
    {
        return [
            ...$this->optionalAddressMessages(),
            'cnpj.digits' => 'O CNPJ deve conter 14 dígitos.',
            'cnpj.unique' => 'Este CNPJ já está cadastrado.',
            'legal_representative_cpf.digits' => 'O CPF do representante legal deve conter 11 dígitos.',
            'industry_sector.enum' => 'Selecione um setor válido para a empresa.',
            'estimated_pet_owners.enum' => 'Selecione uma faixa válida de tutores estimados.',
            'preferred_communication.enum' => 'Selecione um canal de comunicação válido.',
            'budget_range.enum' => 'Selecione uma faixa de orçamento válida.',
        ];
    }
}
