<?php

namespace App\Http\Requests\Professional;

use App\DataTransferObjects\Cpf;
use App\Rules\ValidCpf;
use Illuminate\Foundation\Http\FormRequest;

class StoreProfessionalClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * CPF chega limpo (só dígitos), mesma normalização de
     * `StoreNewPatientAppointmentRequest` — `ClientProvisioningService`/`TutorIdentityResolver`
     * comparam sempre contra a coluna já normalizada.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('cpf')) {
            $this->merge(['cpf' => Cpf::stripMask($this->input('cpf'))]);
        }
    }

    /**
     * Sem `unique:users` de propósito: e-mail/CPF já cadastrado vincula à conta existente
     * (`ClientProvisioningService::provision`) em vez de estourar 422 — cenário comum quando
     * o "novo cliente" já é tutor na plataforma. CPF é opcional (Achado 1 de
     * `docs/gap-simplesvet/specs/19-portal-do-cliente-spec.md`): quando informado, vira a
     * chave forte de identidade em vez do e-mail.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required_without:cpf', 'nullable', 'email', 'max:255'],
            'cpf' => ['nullable', 'digits:'.Cpf::DIGIT_COUNT, app(ValidCpf::class)],
            'phone' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
        ];
    }
}
