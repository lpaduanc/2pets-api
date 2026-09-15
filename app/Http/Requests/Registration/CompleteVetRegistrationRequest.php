<?php

namespace App\Http\Requests\Registration;

use App\DataTransferObjects\Cpf;
use App\Enums\ProfessionalType;
use App\Http\Requests\Concerns\CanonicalizesSpecialties;
use App\Http\Requests\Concerns\FormatsDuplicateFieldErrors;
use App\Http\Requests\Registration\Concerns\HasAddressRules;
use App\Http\Requests\Registration\Concerns\HasProfessionalCapabilityRules;
use App\Rules\ValidCpf;
use App\Rules\ValidCrmv;
use App\Rules\ValidSpecialty;
use App\Services\CrmvValidationService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompleteVetRegistrationRequest extends FormRequest
{
    use CanonicalizesSpecialties;
    use FormatsDuplicateFieldErrors, HasAddressRules, HasProfessionalCapabilityRules;

    public function authorize(): bool
    {
        return true; // Autorização é feita pelo middleware auth:sanctum da rota.
    }

    /** @return list<string> */
    protected function duplicateFields(): array
    {
        return ['cpf', 'crmv'];
    }

    /**
     * Regra de projeto: documento trafega e é gravado limpo (só dígitos). Normalizar antes
     * do `digits:11` é o que evita reprovar um CPF que o app manda mascarado.
     *
     * `crmv` também é normalizado para o formato canônico (`CRMV/UF 12345`) ANTES da
     * validação — é o mesmo formato que `RegistrationCompletionService::vetProfessionalData()`
     * grava (a chamada de `format()` lá é idempotente sobre um valor já canônico). Sem isto,
     * `Rule::unique` compararia o valor cru digitado pelo usuário contra a coluna formatada e
     * nunca acharia a duplicata real.
     */
    protected function prepareForValidation(): void
    {
        $this->canonicalizeSpecialties();

        if ($this->has('cpf')) {
            $this->merge(['cpf' => Cpf::stripMask($this->input('cpf'))]);
        }

        if ($this->has('crmv') && $this->has('crmv_state')) {
            $this->merge([
                'crmv' => app(CrmvValidationService::class)->format(
                    (string) $this->input('crmv'),
                    (string) $this->input('crmv_state'),
                ),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            ...$this->addressRules(),
            'cpf' => [
                'bail', 'required', 'digits:'.Cpf::DIGIT_COUNT, app(ValidCpf::class),
                Rule::unique('users', 'cpf')
                    ->ignore($this->user()?->id)
                    ->withoutTrashed(),
            ],
            'birth_date' => ['required', 'date'],

            'university' => ['required', 'string'],
            'graduation_year' => ['required', 'integer', 'min:1950', 'max:'.date('Y')],
            'courses' => ['nullable', 'array'],

            'crmv' => [
                'bail', 'required', 'string', app(ValidCrmv::class),
                Rule::unique('professionals', 'crmv')
                    ->ignore($this->user()?->id, 'user_id')
                    ->withoutTrashed(),
            ],
            'crmv_state' => ['required', 'string', 'size:2'],
            'specialties' => ['nullable', 'array'],
            'specialties.*' => ['string', app(ValidSpecialty::class)],
            'experience_years' => ['required', 'integer', 'min:0'],
            'service_radius_km' => ['nullable', 'integer', 'min:1'],
            'opening_hours' => ['required', 'string'], // Formato HH:mm
            'closing_hours' => ['required', 'string'], // Formato HH:mm
            'working_days' => ['required', 'array'],
            'description' => ['nullable', 'string'],

            // Segmentação por tipo (docs/segmentacao-cadastro-profissional.md) — dupla trava:
            // a matriz vem de `ProfessionalCapabilityRegistry`, nunca de `if` local.
            ...$this->capabilityRules(ProfessionalType::VET),
        ];
    }

    /**
     * Dependência serviço↔equipamento (`docs/equipamento-vet-volante-e-marketplace-b2b.md`
     * §2.2): não se oferece `imaging`/`laboratory` sem o equipamento correspondente.
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

    public function messages(): array
    {
        return [
            ...$this->addressMessages(),
            ...$this->capabilityMessages(ProfessionalType::VET),
            'cpf.required' => 'O CPF é obrigatório.',
            'cpf.digits' => 'O CPF deve conter 11 dígitos.',
            'cpf.unique' => 'Este CPF já está cadastrado.',
            'birth_date.required' => 'A data de nascimento é obrigatória.',
            'university.required' => 'A universidade de formação é obrigatória.',
            'graduation_year.required' => 'O ano de formatura é obrigatório.',
            'graduation_year.min' => 'Ano de formatura inválido.',
            'graduation_year.max' => 'O ano de formatura não pode ser no futuro.',
            'crmv.required' => 'O CRMV é obrigatório.',
            'crmv.unique' => 'Este CRMV já está cadastrado.',
            'crmv_state.required' => 'A UF do CRMV é obrigatória.',
            'crmv_state.size' => 'A UF do CRMV deve ter 2 letras.',
            'experience_years.required' => 'Os anos de experiência são obrigatórios.',
            'opening_hours.required' => 'O horário de abertura é obrigatório.',
            'closing_hours.required' => 'O horário de fechamento é obrigatório.',
            'working_days.required' => 'Os dias de atendimento são obrigatórios.',
        ];
    }
}
