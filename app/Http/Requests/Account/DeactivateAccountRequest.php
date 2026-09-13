<?php

namespace App\Http\Requests\Account;

use App\Enums\DeactivationReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeactivateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Autorização é feita pelo middleware auth:sanctum da rota.
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'string'],
            'reason' => ['required', Rule::enum(DeactivationReason::class)],
            'note' => ['nullable', 'string', 'max:1000', Rule::requiredIf($this->input('reason') === DeactivationReason::OTHER->value)],
        ];
    }

    public function messages(): array
    {
        return [
            'password.required' => 'A senha é obrigatória.',
            'reason.required' => 'Selecione um motivo.',
            'reason.enum' => 'Motivo inválido.',
            'note.required' => 'Conte um pouco mais sobre o motivo.',
            'note.max' => 'O motivo deve ter no máximo 1000 caracteres.',
        ];
    }
}
