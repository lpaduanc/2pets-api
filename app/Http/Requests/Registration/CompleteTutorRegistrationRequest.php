<?php

namespace App\Http\Requests\Registration;

use App\DataTransferObjects\Cpf;
use App\Http\Requests\Concerns\FormatsDuplicateFieldErrors;
use App\Http\Requests\Registration\Concerns\HasAddressRules;
use App\Rules\ValidCpf;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompleteTutorRegistrationRequest extends FormRequest
{
    use FormatsDuplicateFieldErrors, HasAddressRules;

    /** @var list<string> */
    private const GENDERS = ['male', 'female', 'other', 'not_specified'];

    public function authorize(): bool
    {
        return true; // Autorização é feita pelo middleware auth:sanctum da rota.
    }

    /** @return list<string> */
    protected function duplicateFields(): array
    {
        return ['cpf'];
    }

    /**
     * Regra de projeto: documento trafega e é gravado limpo (só dígitos). Normalizar antes
     * do `digits:11` é o que evita reprovar um CPF que o app manda mascarado.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('cpf')) {
            return;
        }

        $this->merge(['cpf' => Cpf::stripMask($this->input('cpf'))]);
    }

    public function rules(): array
    {
        return [
            ...$this->addressRules(),
            'cpf' => [
                'bail', 'required', 'digits:'.Cpf::DIGIT_COUNT, app(ValidCpf::class),
                // Contrato docs/atendimento-veterinario/07-contrato-agendamento-pet-novo.md §6:
                // uma conta NÃO reivindicada (`password IS NULL`, `registration_status =
                // pending` — nascida do fluxo de paciente novo) não conta como duplicata aqui.
                // Quem se autocadastra e digita esse CPF precisa CONTINUAR aquele cadastro
                // (`TutorAccountClaimService`, chamado pelo controller), nunca ver "CPF já
                // cadastrado". Uma conta de verdade com esse CPF (senha definida OU já aprovada)
                // continua bloqueando normalmente.
                Rule::unique('users', 'cpf')
                    ->ignore($this->user()?->id)
                    ->withoutTrashed()
                    ->where(function ($query) {
                        $query->whereNotNull('password')
                            ->orWhere('registration_status', '!=', 'pending');
                    }),
            ],
            'birth_date' => ['required', 'date'],
            'gender' => ['nullable', 'string', 'in:'.implode(',', self::GENDERS)],
            'occupation' => ['nullable', 'string', 'max:255'],

            // Coordenadas do Google Places (autocomplete). Quando vêm preenchidas, dispensam
            // a geocodificação por endereço no service.
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],

            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            ...$this->addressMessages(),
            'cpf.required' => 'O CPF é obrigatório.',
            'cpf.digits' => 'O CPF deve conter 11 dígitos.',
            'cpf.unique' => 'Este CPF já está cadastrado.',
            'birth_date.required' => 'A data de nascimento é obrigatória.',
            'birth_date.date' => 'Data de nascimento inválida.',
            'gender.in' => 'Gênero inválido.',
            'avatar.image' => 'O arquivo enviado deve ser uma imagem.',
            'avatar.mimes' => 'A foto deve estar em jpg, jpeg, png ou webp.',
            'avatar.max' => 'A foto deve ter no máximo 5MB.',
        ];
    }
}
