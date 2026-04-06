<?php

namespace App\Http\Requests\Pet;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization is handled by auth:sanctum middleware
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'species' => ['sometimes', 'required', 'in:dog,cat'],
            'breed' => ['nullable', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'gender' => ['sometimes', 'required', 'in:male,female'],
            'weight' => ['nullable', 'numeric', 'min:0', 'max:200'],
            'color' => ['nullable', 'string', 'max:255'],
            'neutered' => ['boolean'],
            'blood_type' => ['nullable', 'string', 'max:50'],
            'allergies' => ['nullable', 'string', 'max:1000'],
            'chronic_diseases' => ['nullable', 'string', 'max:1000'],
            'current_medications' => ['nullable', 'string', 'max:1000'],
            'temperament' => ['nullable', 'array'],
            'temperament.*' => ['string', 'max:100'],
            'behavior_notes' => ['nullable', 'string', 'max:1000'],
            'social_with' => ['nullable', 'array'],
            'social_with.*' => ['string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'image_url' => ['nullable', 'url', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'species.in' => 'Especie invalida. Escolha entre cachorro ou gato.',
            'gender.in' => 'Sexo invalido.',
            'birth_date.before_or_equal' => 'A data de nascimento nao pode ser no futuro.',
            'weight.min' => 'O peso deve ser um valor positivo.',
        ];
    }
}
