<?php

namespace App\Http\Requests\PetVetAccess;

use App\Enums\VetAccessLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GrantVetAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Autorização verificada no controller via ownership do pet
    }

    public function rules(): array
    {
        return [
            'pet_id' => ['required', 'integer', 'exists:pets,id'],
            'veterinarian_id' => ['required', 'integer', 'exists:users,id'],
            'access_level' => ['sometimes', Rule::enum(VetAccessLevel::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'pet_id.required' => 'O pet é obrigatório.',
            'pet_id.exists' => 'Pet não encontrado.',
            'veterinarian_id.required' => 'O veterinário é obrigatório.',
            'veterinarian_id.exists' => 'Veterinário não encontrado.',
            'access_level.enum' => 'Nível de acesso inválido. Use: read, write ou full.',
        ];
    }
}
