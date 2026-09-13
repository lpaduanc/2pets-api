<?php

namespace App\Http\Requests\Registration;

use App\DataTransferObjects\Cnpj;
use App\DataTransferObjects\Cpf;
use App\Enums\ProfessionalType;
use App\Http\Requests\Concerns\FormatsDuplicateFieldErrors;
use App\Http\Requests\Registration\Concerns\HasAddressRules;
use App\Http\Requests\Registration\Concerns\HasProfessionalCapabilityRules;
use App\Rules\ValidCpf;
use App\Rules\ValidCrmv;
use App\Support\Registration\ProfessionalCapabilityRegistry;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Conclusão de cadastro dos profissionais que NÃO são veterinário volante (clínica,
 * laboratório, petshop, pet hotel, banho e tosa, adestramento) — ver `CompleteVetRegistrationRequest`
 * para o fluxo do vet, que tem campos próprios (CRMV, universidade, especialidades).
 *
 * O `cpf` aqui é do REPRESENTANTE — a pessoa que está completando o cadastro e que vira
 * `owner` da `Organization` (ver `BusinessOrganizationRegistrar`), nunca do responsável
 * técnico. Antes desta mudança, conta de negócio era a única sem nenhuma pessoa identificada.
 */
class CompleteGenericProfessionalRegistrationRequest extends FormRequest
{
    use FormatsDuplicateFieldErrors, HasAddressRules, HasProfessionalCapabilityRules;

    public function authorize(): bool
    {
        return true; // Autorização é feita pelo middleware auth:sanctum da rota.
    }

    /** @return list<string> */
    protected function duplicateFields(): array
    {
        return ['cpf', 'cnpj'];
    }

    /**
     * Regra de projeto: documento trafega e é gravado limpo (só dígitos). Normalizar antes
     * do `digits:14`/`digits:11` é o que evita reprovar um documento que o app manda mascarado.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('cnpj')) {
            $this->merge(['cnpj' => Cnpj::stripMask($this->input('cnpj'))]);
        }

        if ($this->has('cpf')) {
            $this->merge(['cpf' => Cpf::stripMask($this->input('cpf'))]);
        }
    }

    public function rules(): array
    {
        $professionalType = $this->resolveProfessionalType();

        return [
            ...$this->addressRules(),
            'cpf' => [
                'bail', 'required', 'digits:'.Cpf::DIGIT_COUNT, app(ValidCpf::class),
                Rule::unique('users', 'cpf')
                    ->ignore($this->user()?->id)
                    ->withoutTrashed(),
            ],
            'business_name' => ['required', 'string'],
            'cnpj' => [
                'required', 'digits:'.Cnpj::DIGIT_COUNT,
                Rule::unique('professionals', 'cnpj')
                    ->ignore($this->user()?->id, 'user_id')
                    ->withoutTrashed(),
            ],

            'opening_hours' => ['required', 'string'],
            'closing_hours' => ['required', 'string'],
            'working_days' => ['required', 'array'],
            'description' => ['nullable', 'string'],
            'products_sold' => ['nullable', 'array'],
            'certifications' => ['nullable', 'array'],
            'service_radius_km' => $professionalType === null
                ? ['prohibited']
                : $this->serviceRadiusRules(ProfessionalCapabilityRegistry::for($professionalType)->serviceRadius),

            // Segmentação por tipo (docs/segmentacao-cadastro-profissional.md) — dupla trava:
            // a matriz vem de `ProfessionalCapabilityRegistry`, nunca de `if` local.
            ...($professionalType === null ? [] : $this->capabilityRules($professionalType)),

            ...$this->technicalResponsibleRules(),
        ];
    }

    private function resolveProfessionalType(): ?ProfessionalType
    {
        return ProfessionalType::tryFrom((string) $this->user()?->user_type);
    }

    /**
     * Dependência serviço↔equipamento (`docs/equipamento-vet-volante-e-marketplace-b2b.md`
     * §2.2): achado colateral do especialista — `clinic`/`laboratory` já podiam marcar
     * `imaging`/`laboratory` sem nenhum equipamento correspondente, a mesma falha que motivou
     * tirar `imaging` do `vet` originalmente. Roda para qualquer tipo genérico; tipos sem
     * `imaging`/`laboratory` no catálogo (petshop, pet_hotel, grooming, training) nunca têm
     * esses valores em `services_offered` — já barrado por `capabilityRules()` — então o laço
     * não encontra nada para reportar.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $this->addServiceEquipmentDependencyErrors(
                $v,
                (array) $this->input('services_offered', []),
                (array) $this->input('equipment', []),
            );
        });
    }

    /**
     * RT (responsável técnico) só é exigido para clínica e laboratório, e é uma escolha
     * explícita: o próprio representante (`technical_responsible_is_self = true`, exige só
     * CRMV/UF — nome e e-mail já são os do representante) ou um terceiro convidado por e-mail
     * (`false`, exige nome/e-mail/CRMV/UF; o convite é disparado por
     * `OrganizationInvitationService`, ver `RegistrationCompletionService`).
     *
     * @return array<string, list<string|object>>
     */
    private function technicalResponsibleRules(): array
    {
        if (! $this->requiresTechnicalResponsible()) {
            return [];
        }

        $isThirdParty = $this->isThirdPartyTechnicalResponsible();

        return [
            'technical_responsible_is_self' => ['required', 'boolean'],
            'technical_responsible_crmv' => [
                'bail', 'required', 'string',
                app(ValidCrmv::class, ['stateField' => 'technical_responsible_crmv_state']),
            ],
            'technical_responsible_crmv_state' => ['required', 'string', 'size:2'],
            'technical_responsible_name' => [$isThirdParty ? 'required' : 'nullable', 'string'],
            'technical_responsible_email' => [$isThirdParty ? 'required' : 'nullable', 'email'],
        ];
    }

    /**
     * Fonte única: `ProfessionalCapabilityRegistry` (docs/segmentacao-cadastro-profissional.md
     * §2) — clínica e laboratório exigem RT, os demais tipos não.
     */
    private function requiresTechnicalResponsible(): bool
    {
        $professionalType = $this->resolveProfessionalType();

        return $professionalType !== null
            && ProfessionalCapabilityRegistry::for($professionalType)->requiresTechnicalResponsible;
    }

    private function isThirdPartyTechnicalResponsible(): bool
    {
        return ! $this->boolean('technical_responsible_is_self');
    }

    public function messages(): array
    {
        $professionalType = $this->resolveProfessionalType();

        return [
            ...$this->addressMessages(),
            ...($professionalType === null ? [] : $this->capabilityMessages($professionalType)),
            'cpf.required' => 'O CPF do representante é obrigatório.',
            'cpf.digits' => 'O CPF deve conter 11 dígitos.',
            'cpf.unique' => 'Este CPF já está cadastrado.',
            'business_name.required' => 'O nome do negócio é obrigatório.',
            'cnpj.required' => 'O CNPJ é obrigatório.',
            'cnpj.digits' => 'O CNPJ deve conter 14 dígitos.',
            'cnpj.unique' => 'Este CNPJ já está cadastrado.',
            'opening_hours.required' => 'O horário de abertura é obrigatório.',
            'closing_hours.required' => 'O horário de fechamento é obrigatório.',
            'working_days.required' => 'Os dias de funcionamento são obrigatórios.',
            'technical_responsible_is_self.required' => 'Informe se o responsável técnico é o próprio representante.',
            'technical_responsible_name.required' => 'Informe o nome do responsável técnico.',
            'technical_responsible_email.required' => 'Informe o e-mail do responsável técnico para enviar o convite.',
            'technical_responsible_email.email' => 'Informe um e-mail válido para o responsável técnico.',
            'technical_responsible_crmv.required' => 'Informe o CRMV do responsável técnico.',
            'technical_responsible_crmv_state.required' => 'Informe a UF do CRMV do responsável técnico.',
            'technical_responsible_crmv_state.size' => 'A UF do CRMV deve ter 2 letras.',
        ];
    }
}
