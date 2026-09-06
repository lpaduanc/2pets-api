<?php

namespace App\Http\Requests\User;

use App\Enums\ProfessionalType;
use App\Models\User;
use Closure;
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
            'cpf' => ['sometimes', 'nullable', 'string', 'max:14', $this->uniqueCpfRule()],
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
            $digitsOnly = preg_replace('/\D/', '', (string) $value);

            $cpfBelongsToAnotherUser = User::where('cpf', $digitsOnly)
                ->where('id', '!=', $this->user()->id)
                ->exists();

            if ($cpfBelongsToAnotherUser) {
                $fail('Este CPF ja esta cadastrado.');
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
            'professional.cnpj' => ['sometimes', 'nullable', 'string', 'max:18'],
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
            'professional.opening_hours' => ['sometimes', 'nullable', 'date_format:H:i'],
            'professional.closing_hours' => ['sometimes', 'nullable', 'date_format:H:i'],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function companyRules(): array
    {
        return [
            'company' => ['sometimes', 'array'],
            'company.company_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'company.cnpj' => ['sometimes', 'nullable', 'string', 'max:18'],
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
        ];
    }
}
