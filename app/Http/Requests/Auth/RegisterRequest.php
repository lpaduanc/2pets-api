<?php

namespace App\Http\Requests\Auth;

use App\DataTransferObjects\Cnpj;
use App\Enums\ProfessionalType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    /**
     * `user_type` na etapa 1 do cadastro não é só um dos 7 tipos profissionais: também
     * aceita `tutor` (não é profissional) e `company` (lead B2B pendente de qualificação,
     * não é tipo de negócio — ver `docs/taxonomia-professional-type.md` §6).
     *
     * @return list<string>
     */
    private const NON_PROFESSIONAL_USER_TYPES = ['tutor', 'company'];

    public function authorize(): bool
    {
        return true; // Public endpoint
    }

    /** Regra de projeto: documento trafega limpo (so digitos). Ver `DocumentNumber`. */
    protected function prepareForValidation(): void
    {
        $additionalData = $this->input('additional_data');

        if (! is_array($additionalData) || ! array_key_exists('cnpj', $additionalData)) {
            return;
        }

        $additionalData['cnpj'] = Cnpj::stripMask($additionalData['cnpj']);

        $this->merge(['additional_data' => $additionalData]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'phone' => ['required', 'string', 'max:20'],
            'user_type' => ['required', Rule::in([
                ...self::NON_PROFESSIONAL_USER_TYPES,
                ...ProfessionalType::values(),
            ])],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'additional_data' => ['array', 'nullable'],
            'additional_data.cnpj' => ['nullable', 'digits:'.Cnpj::DIGIT_COUNT],
            'additional_data.employee_count' => ['nullable', 'string', 'max:50'],
            'additional_data.message' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'O nome e obrigatorio.',
            'email.required' => 'O e-mail e obrigatorio.',
            'email.unique' => 'Este e-mail ja esta cadastrado.',
            'phone.required' => 'O telefone e obrigatorio.',
            'user_type.required' => 'O tipo de usuario e obrigatorio.',
            'user_type.in' => 'Tipo de usuario invalido.',
            'password.required' => 'A senha e obrigatoria.',
            'password.min' => 'A senha deve ter pelo menos 8 caracteres.',
            'password.confirmed' => 'As senhas nao conferem.',
        ];
    }
}
