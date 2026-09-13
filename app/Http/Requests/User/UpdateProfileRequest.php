<?php

namespace App\Http\Requests\User;

use App\DataTransferObjects\Cnpj;
use App\DataTransferObjects\Cpf;
use App\Enums\ProfessionalType;
use App\Http\Requests\Registration\Concerns\HasProfessionalCapabilityRules;
use App\Models\Professional;
use App\Models\User;
use App\Rules\ValidCpf;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Valida `PUT /api/profile` para os tres tipos de conta (tutor, profissional,
 * empresa). Tudo e `sometimes` — e um patch parcial, so o que vier no
 * payload e validado/atualizado.
 *
 * Campos somente leitura do `Professional`/`Company` (`is_crmv_verified`,
 * `average_rating`, `total_reviews`, `is_featured`) e do proprio `User`
 * (`role`, `registration_status`, `profile_completed`, `user_id`)
 * deliberadamente NAO tem regra aqui: `validated()` so devolve chaves com
 * regra declarada, entao qualquer uma dessas chaves enviadas pelo cliente e
 * descartada antes de chegar a qualquer service.
 */
class UpdateProfileRequest extends FormRequest
{
    use HasProfessionalCapabilityRules;

    private const GENDERS = ['male', 'female', 'other', 'not_specified'];

    public function authorize(): bool
    {
        return true; // Authorization is handled by auth:sanctum middleware
    }

    /**
     * Normaliza o payload legado — `address` como string solta + `city`/
     * `state`/`zip_code` na raiz — para o formato aninhado `address.*`, para
     * que uma unica regra de validacao (e um unico caminho no service) sirva
     * os dois formatos.
     */
    protected function prepareForValidation(): void
    {
        $this->normalizeDocuments();
        $this->normalizeLegacyAddress();
    }

    /**
     * Regra de projeto: documento trafega e e gravado limpo (so digitos). Normalizar ANTES
     * da validacao e o que faz `digits:` e as checagens de unicidade compararem o mesmo
     * formato que esta no banco — com mascara, um CPF duplicado passa pelo `unique` e so
     * estoura no indice, virando 500.
     */
    private function normalizeDocuments(): void
    {
        if ($this->has('cpf')) {
            $this->merge(['cpf' => Cpf::stripMask($this->input('cpf'))]);
        }

        $this->normalizeNestedDocument('professional', 'cnpj');
        $this->normalizeNestedDocument('company', 'cnpj');
    }

    private function normalizeNestedDocument(string $section, string $field): void
    {
        $payload = $this->input($section);

        if (! is_array($payload) || ! array_key_exists($field, $payload)) {
            return;
        }

        $payload[$field] = Cnpj::stripMask($payload[$field]);

        $this->merge([$section => $payload]);
    }

    private function normalizeLegacyAddress(): void
    {
        if (is_array($this->input('address'))) {
            return;
        }

        if (! $this->hasAny(['address', 'city', 'state', 'zip_code'])) {
            return;
        }

        $this->merge([
            'address' => array_filter([
                'street' => $this->input('address'),
                'city' => $this->input('city'),
                'state' => $this->input('state'),
                'zip_code' => $this->input('zip_code'),
            ], fn (mixed $value): bool => $value !== null),
        ]);
    }

    public function rules(): array
    {
        return [
            ...$this->personalDataRules(),
            ...$this->addressRules(),
            ...$this->professionalRules(),
            ...$this->companyRules(),
            'current_password' => ['nullable', 'string', 'required_with:new_password'],
            'new_password' => ['nullable', 'string', 'min:8', 'confirmed', 'required_with:current_password'],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function personalDataRules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'unique:users,email,'.$this->user()->id],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            // `digits:11` sozinho aceita qualquer sequência de 11 algarismos. Os três fluxos de
            // cadastro (`Registration/Complete*Request`) já exigiam `ValidCpf`; editar o perfil
            // não — mesmo campo, dois padrões. O sintoma real: um CNPJ colado num campo com
            // máscara de 11 dígitos chega truncado (48328865000108 → 48328865000), passa no
            // `digits` e grava como CPF. Foi assim que uma conta de clínica em dev ficou com CPF
            // inválido.
            'cpf' => ['sometimes', 'nullable', 'bail', 'digits:'.Cpf::DIGIT_COUNT, app(ValidCpf::class), $this->uniqueCpfRule()],
            'birth_date' => ['sometimes', 'nullable', 'date', 'before:today'],
            'gender' => ['sometimes', 'nullable', Rule::in(self::GENDERS)],
            'occupation' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * `User::$cpf` e sempre normalizado para digitos-apenas pelo mutator do
     * model antes de gravar — uma regra `unique:users,cpf` comum compararia
     * o valor formatado do input (`123.456.789-00`) contra o valor limpo no
     * banco e nunca acusaria duplicata de verdade.
     */
    private function uniqueCpfRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $cpfBelongsToAnotherUser = User::query()
                ->where('cpf', Cpf::stripMask($value))
                ->where('id', '!=', $this->user()->id)
                ->exists();

            if ($cpfBelongsToAnotherUser) {
                $fail('Este CPF ja esta cadastrado.');
            }
        };
    }

    /**
     * Mesmo raciocinio do CPF: `professionals.cnpj` tem indice unico e o mutator grava
     * limpo, entao a checagem precisa comparar digitos contra digitos. Sem isto, um CNPJ
     * ja usado passa na validacao e a gravacao morre com 23505 (HTTP 500).
     */
    private function uniqueProfessionalCnpjRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $cnpjBelongsToAnother = Professional::query()
                ->where('cnpj', Cnpj::stripMask($value))
                ->where('user_id', '!=', $this->user()->id)
                ->exists();

            if ($cnpjBelongsToAnother) {
                $fail('Este CNPJ ja esta cadastrado.');
            }
        };
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function addressRules(): array
    {
        return [
            'address' => ['sometimes', 'array'],
            'address.street' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address.number' => ['sometimes', 'nullable', 'string', 'max:20'],
            'address.complement' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address.neighborhood' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address.city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'address.state' => ['sometimes', 'nullable', 'string', 'max:2'],
            'address.zip_code' => ['sometimes', 'nullable', 'string', 'max:10'],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function professionalRules(): array
    {
        return [
            'professional' => ['sometimes', 'array'],
            'professional.professional_type' => ['sometimes', 'nullable', Rule::in(ProfessionalType::values())],
            'professional.business_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'professional.cnpj' => ['sometimes', 'nullable', 'digits:'.Cnpj::DIGIT_COUNT, $this->uniqueProfessionalCnpjRule()],
            'professional.crmv' => ['sometimes', 'nullable', 'string', 'max:255'],
            'professional.crmv_state' => ['sometimes', 'nullable', 'string', 'max:2'],
            'professional.specialties' => ['sometimes', 'nullable', 'array'],
            'professional.services_offered' => ['sometimes', 'nullable', 'array'],
            'professional.service_radius_km' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:200'],
            'professional.description' => ['sometimes', 'nullable', 'string'],
            'professional.university' => ['sometimes', 'nullable', 'string', 'max:255'],
            'professional.graduation_year' => ['sometimes', 'nullable', 'integer', 'min:1900', 'max:'.now()->year],
            'professional.experience_years' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:80'],
            'professional.working_days' => ['sometimes', 'nullable', 'array'],
            // Aceita H:i (formato emitido por ProfessionalProfileResource e por
            // <input type="time">) e H:i:s (compat com cliente antigo que
            // reenvia o valor cru da coluna `time` do Postgres) — liberal no
            // que aceita, estrito no que este mesmo endpoint emite no GET.
            'professional.opening_hours' => ['sometimes', 'nullable', 'date_format:H:i,H:i:s'],
            'professional.closing_hours' => ['sometimes', 'nullable', 'date_format:H:i,H:i:s'],
            'professional.technical_responsible_id' => ['sometimes', 'nullable', 'exists:users,id'],
            'professional.technical_responsible_name' => [
                'sometimes', 'nullable', 'string', 'max:255',
                Rule::requiredIf(fn (): bool => $this->technicalResponsibleIsRequired()),
            ],
            'professional.technical_responsible_crmv' => [
                'sometimes', 'nullable', 'string', 'max:255',
                Rule::requiredIf(fn (): bool => $this->technicalResponsibleIsRequired()),
            ],
            'professional.technical_responsible_crmv_state' => [
                'sometimes', 'nullable', 'string', 'size:2',
                Rule::requiredIf(fn (): bool => $this->technicalResponsibleIsRequired()),
            ],
            // Dupla trava por `professional_type` (docs/segmentacao-cadastro-profissional.md):
            // os 18 campos de "Diferenciais e Facilidades" + `species_served`/`sizes_served`
            // eram capturados no cadastro (Onda 3) mas não podiam ser editados depois —
            // `professional.services_offered` acima cobre só o formato, esta chamada acrescenta
            // a checagem "este serviço/equipamento/facilidade é permitido para ESTE tipo?" e
            // sobrescreve a entrada solta de `services_offered`/`equipment` já declarada.
            ...$this->professionalCapabilityPatchRules(),
        ];
    }

    /**
     * `null` quando o usuário não tem `Professional` vinculado e não mandou
     * `professional.professional_type` no payload — nesse caso nenhuma regra de capacidade é
     * aplicada (não há tipo contra o qual segmentar).
     *
     * @return array<string, array<int, mixed>>
     */
    private function professionalCapabilityPatchRules(): array
    {
        $type = $this->effectiveProfessionalType();

        return $type === null ? [] : $this->capabilityPatchRules($type, 'professional.');
    }

    /** @return array<string, string> */
    private function professionalCapabilityPatchMessages(): array
    {
        $type = $this->effectiveProfessionalType();

        return $type === null ? [] : $this->capabilityPatchMessages($type, 'professional.');
    }

    /**
     * Dependência serviço↔equipamento (`docs/equipamento-vet-volante-e-marketplace-b2b.md`
     * §2.2) também vale para edição, não só para o cadastro inicial. Só roda quando o payload
     * toca `services_offered` OU `equipment` — sem isso, editar telefone num perfil com dado
     * legado incoerente (profissional cadastrado antes desta regra existir) quebraria uma
     * requisição que nem chegou perto desses dois campos. Quando um dos dois vem no payload e
     * o outro não, cai para o valor já persistido — mesma lógica de "estado efetivo depois do
     * patch" que `effectiveProfessionalType()` já usa para o tipo.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if (! $this->has('professional.services_offered') && ! $this->has('professional.equipment')) {
                return;
            }

            $professional = $this->user()->professional;

            $this->addServiceEquipmentDependencyErrors(
                $v,
                (array) $this->input('professional.services_offered', $professional?->services_offered ?? []),
                (array) $this->input('professional.equipment', $professional?->equipment ?? []),
                'professional.services_offered',
            );
        });
    }

    /**
     * RT (responsavel tecnico) e obrigatorio para clinica/laboratorio que
     * nao vincula um profissional ja cadastrado via `technical_responsible_id`.
     */
    private function technicalResponsibleIsRequired(): bool
    {
        if (! $this->isClinicOrLaboratory()) {
            return false;
        }

        return $this->input('professional.technical_responsible_id') === null;
    }

    private function isClinicOrLaboratory(): bool
    {
        return in_array(
            $this->effectiveProfessionalType(),
            [ProfessionalType::CLINIC, ProfessionalType::LABORATORY],
            true
        );
    }

    /**
     * `PUT /profile` e um patch parcial: `professional.professional_type`
     * pode nao vir no payload. Quando ausente, cai para o tipo ja persistido
     * do profissional autenticado — nunca assume tutor/vet por omissao.
     */
    private function effectiveProfessionalType(): ?ProfessionalType
    {
        $inputType = $this->input('professional.professional_type');

        if ($inputType !== null) {
            return ProfessionalType::tryFrom($inputType);
        }

        return $this->user()->professional?->professional_type;
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function companyRules(): array
    {
        return [
            'company' => ['sometimes', 'array'],
            'company.company_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'company.cnpj' => ['sometimes', 'nullable', 'digits:'.Cnpj::DIGIT_COUNT],
            'company.contact_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'company.contact_position' => ['sometimes', 'nullable', 'string', 'max:255'],
            'company.phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'company.website' => ['sometimes', 'nullable', 'string', 'max:255'],
            'company.employee_count' => ['sometimes', 'nullable', 'string', 'max:255'],
            'company.benefit_type' => ['sometimes', 'nullable', 'string', 'max:255'],
            'company.notes' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'Este e-mail ja esta em uso.',
            'birth_date.before' => 'A data de nascimento deve ser no passado.',
            'gender.in' => 'Genero invalido.',
            'new_password.min' => 'A nova senha deve ter pelo menos 8 caracteres.',
            'new_password.confirmed' => 'A confirmacao da nova senha nao confere.',
            'new_password.required_with' => 'Informe a nova senha para trocar a senha.',
            'current_password.required_with' => 'Informe a senha atual para trocar a senha.',
            'professional.technical_responsible_id.exists' => 'Responsavel tecnico invalido.',
            'professional.technical_responsible_name.required' => 'Informe o nome do responsavel tecnico.',
            'professional.technical_responsible_crmv.required' => 'Informe o CRMV do responsavel tecnico.',
            'professional.technical_responsible_crmv_state.required' => 'Informe a UF do CRMV do responsavel tecnico.',
            'professional.technical_responsible_crmv_state.size' => 'A UF do CRMV deve ter 2 letras.',
            ...$this->professionalCapabilityPatchMessages(),
        ];
    }
}
