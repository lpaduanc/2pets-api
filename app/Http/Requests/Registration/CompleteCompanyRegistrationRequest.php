<?php

namespace App\Http\Requests\Registration;

use App\DataTransferObjects\Cnpj;
use App\DataTransferObjects\Cpf;
use App\Enums\BudgetRange;
use App\Enums\EstimatedPetOwnersRange;
use App\Enums\IndustrySector;
use App\Enums\PreferredCommunicationChannel;
use App\Http\Requests\Concerns\FormatsDuplicateFieldErrors;
use App\Http\Requests\Registration\Concerns\HasAddressRules;
use App\Rules\ValidCpf;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompleteCompanyRegistrationRequest extends FormRequest
{
    use FormatsDuplicateFieldErrors, HasAddressRules;

    public function authorize(): bool
    {
        return true; // Autorização é feita pelo middleware auth:sanctum da rota.
    }

    /** @return list<string> */
    protected function duplicateFields(): array
    {
        return ['cnpj'];
    }

    /**
     * Regra de projeto: documento trafega e é gravado limpo (só dígitos). Normalizar antes
     * do `digits:14`/`digits:11` é o que evita reprovar um documento que o app manda
     * mascarado — vale tanto para o CNPJ da empresa quanto para o CPF do representante legal.
     */
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
            ...$this->addressRules(),
            'company_name' => ['required', 'string'],
            'cnpj' => [
                'required', 'digits:'.Cnpj::DIGIT_COUNT,
                Rule::unique('companies', 'cnpj')->ignore($this->user()?->id, 'user_id'),
            ],
            'contact_name' => ['required', 'string'],
            'contact_position' => ['nullable', 'string'],
            'phone' => ['required', 'string'],
            'employee_count' => ['required', 'string'],
            'website' => ['nullable', 'string'],

            'benefit_type' => ['required', 'string'],
            'notes' => ['nullable', 'string'],

            // Representante legal — CPF valida só o dígito verificador, DE PROPÓSITO sem
            // `Rule::unique`: a mesma pessoa pode ser tutora na plataforma E representante de
            // uma empresa parceira (não colide com `users.cpf`), e duas empresas distintas
            // podem legitimamente ter o mesmo representante (não é único entre `companies`).
            'legal_representative_name' => ['required', 'string'],
            'legal_representative_cpf' => ['bail', 'required', 'digits:'.Cpf::DIGIT_COUNT, app(ValidCpf::class)],
            'legal_representative_birth_date' => ['required', 'date'],
            'legal_representative_phone' => ['required', 'string'],

            // Qualificação comercial do Clube de Vantagens — só `industry_sector` é
            // obrigatório na tela (`CompleteProfileCompany.vue`), os demais são opcionais.
            'industry_sector' => ['required', Rule::enum(IndustrySector::class)],
            'has_pet_policy' => ['nullable', 'boolean'],
            'estimated_pet_owners' => ['nullable', Rule::enum(EstimatedPetOwnersRange::class)],
            'preferred_communication' => ['nullable', Rule::enum(PreferredCommunicationChannel::class)],
            'budget_range' => ['nullable', Rule::enum(BudgetRange::class)],
            // `start_date_preference` é um `<q-input type="date">` na tela, não um select de
            // opções fechadas — só precisa ser uma data válida.
            'start_date_preference' => ['nullable', 'date'],
            'interested_services' => ['nullable', 'array'],
            'interested_services.*' => ['string'],
        ];
    }

    public function messages(): array
    {
        return [
            ...$this->addressMessages(),
            'company_name.required' => 'O nome da empresa é obrigatório.',
            'cnpj.required' => 'O CNPJ é obrigatório.',
            'cnpj.digits' => 'O CNPJ deve conter 14 dígitos.',
            'cnpj.unique' => 'Este CNPJ já está cadastrado.',
            'contact_name.required' => 'O nome do contato é obrigatório.',
            'phone.required' => 'O telefone é obrigatório.',
            'employee_count.required' => 'A quantidade de funcionários é obrigatória.',
            'benefit_type.required' => 'O tipo de benefício é obrigatório.',

            'legal_representative_name.required' => 'O nome do representante legal é obrigatório.',
            'legal_representative_cpf.required' => 'O CPF do representante legal é obrigatório.',
            'legal_representative_cpf.digits' => 'O CPF do representante legal deve conter 11 dígitos.',
            'legal_representative_birth_date.required' => 'A data de nascimento do representante legal é obrigatória.',
            'legal_representative_phone.required' => 'O telefone do representante legal é obrigatório.',

            'industry_sector.required' => 'O setor da empresa é obrigatório.',
            // Sem `lang/pt_BR/validation.php` no projeto, `Rule::enum` cai na mensagem padrão
            // do Laravel em inglês ("The selected industry sector is invalid.") — corrigido
            // aqui campo a campo para os 4 enums de qualificação comercial (Onda 2).
            'industry_sector.enum' => 'Selecione um setor válido para a empresa.',
            'estimated_pet_owners.enum' => 'Selecione uma faixa válida de tutores estimados.',
            'preferred_communication.enum' => 'Selecione um canal de comunicação válido.',
            'budget_range.enum' => 'Selecione uma faixa de orçamento válida.',
        ];
    }
}
