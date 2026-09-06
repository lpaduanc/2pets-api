<?php

namespace App\Http\Requests\PetVetAccess;

use App\Enums\VetAccessLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RequestVetAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            // Exatamente um dos dois: pet_id (pet já existe) ou tutor_cpf (vet inicia por CPF).
            'pet_id' => 'required_without:tutor_cpf|nullable|integer|exists:pets,id',
            'tutor_cpf' => 'required_without:pet_id|nullable|string|size:11',
            'pet_data' => 'required_without:pet_id|nullable|array',
            'pet_data.name' => 'required_with:pet_data|string|max:255',
            'pet_data.species' => ['required_with:pet_data', Rule::in(['dog', 'cat'])],
            'pet_data.breed' => 'nullable|string|max:255',
            'pet_data.birth_date' => 'nullable|date|before_or_equal:today',
            'pet_data.gender' => ['required_with:pet_data', Rule::in(['male', 'female'])],

            'access_level' => ['nullable', Rule::in(array_column(VetAccessLevel::cases(), 'value'))],
            'message' => 'nullable|string|max:1000',
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'pet_id' => ['description' => 'ID do pet existente na plataforma. Obrigatório se tutor_cpf não for informado.'],
            'tutor_cpf' => ['description' => 'CPF (11 dígitos) do tutor. Obrigatório se pet_id não for informado.'],
            'pet_data' => ['description' => 'Dados básicos do pet — usado quando o vet está criando o pet pelo CPF do tutor.'],
            'access_level' => ['description' => 'Nível de acesso solicitado: read ou write.'],
            'message' => ['description' => 'Mensagem opcional exibida ao tutor na hora de aprovar.'],
        ];
    }
}
