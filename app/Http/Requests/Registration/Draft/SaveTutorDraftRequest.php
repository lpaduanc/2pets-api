<?php

namespace App\Http\Requests\Registration\Draft;

use App\DataTransferObjects\Cpf;
use App\Http\Requests\Concerns\FormatsDuplicateFieldErrors;
use App\Http\Requests\Registration\Draft\Concerns\HasOptionalAddressRules;
use App\Rules\ValidCpf;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /register/draft/tutor` — autosave progressivo, por isso nenhum campo é `required`:
 * o usuário pode salvar o rascunho em qualquer ponto do formulário. Cada campo, quando
 * presente, precisa ser um valor válido — nunca dado cru sem checagem, que era o
 * comportamento antes desta classe existir.
 */
class SaveTutorDraftRequest extends FormRequest
{
    use FormatsDuplicateFieldErrors, HasOptionalAddressRules;

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

    protected function prepareForValidation(): void
    {
        if ($this->has('cpf')) {
            $this->merge(['cpf' => Cpf::stripMask($this->input('cpf'))]);
        }
    }

    public function rules(): array
    {
        return [
            ...$this->optionalAddressRules(),
            'cpf' => [
                'sometimes', 'nullable', 'digits:'.Cpf::DIGIT_COUNT, app(ValidCpf::class),
                Rule::unique('users', 'cpf')->ignore($this->user()?->id)->withoutTrashed(),
            ],
            'birth_date' => ['sometimes', 'nullable', 'date'],
            'gender' => ['sometimes', 'nullable', Rule::in(self::GENDERS)],
            'occupation' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            ...$this->optionalAddressMessages(),
            'cpf.digits' => 'O CPF deve conter 11 dígitos.',
            'cpf.unique' => 'Este CPF já está cadastrado.',
            'gender.in' => 'Selecione um gênero válido.',
        ];
    }
}
