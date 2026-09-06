<?php

namespace App\Http\Requests\Pet;

use Illuminate\Foundation\Http\FormRequest;

class StorePetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization is handled by auth:sanctum middleware
    }

    /**
     * FormData (multipart) serializes arrays as JSON strings.
     * Decode these back to arrays before validation.
     */
    protected function prepareForValidation(): void
    {
        $jsonFields = [
            'coat_colors',
            'food_types',
            'dietary_restrictions',
            'food_allergies',
            'chronic_conditions',
            'surgeries',
            'medications',
            'vaccines',
            'exercise_types',
            'temperament',
            'social_with',
        ];

        $decoded = [];
        foreach ($jsonFields as $field) {
            $value = $this->input($field);
            if (is_string($value) && str_starts_with(trim($value), '[')) {
                $parsed = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $decoded[$field] = $parsed;
                }
            }
        }

        // Coerce boolean-ish strings sent via FormData.
        foreach (['daily_walk', 'previous_hospitalizations'] as $boolField) {
            $val = $this->input($boolField);
            if ($val === 'true' || $val === '1' || $val === 1) {
                $decoded[$boolField] = true;
            } elseif ($val === 'false' || $val === '0' || $val === 0) {
                $decoded[$boolField] = false;
            }
        }

        if ($decoded) {
            $this->merge($decoded);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'species' => ['required', 'in:dog,cat,bird,reptile,rodent,fish,other'],
            'breed' => ['nullable', 'string', 'max:255'],
            'breed_id' => ['nullable', 'integer', 'exists:breeds,id'],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'gender' => ['required', 'in:male,female,unknown'],

            // Physical
            'weight' => ['nullable', 'numeric', 'min:0', 'max:200'],
            'weight_kg' => ['nullable', 'numeric', 'min:0', 'max:200'],
            'size' => ['nullable', 'in:mini,small,medium,large,giant'],
            'color' => ['nullable', 'string', 'max:255'],
            'coat_colors' => ['nullable', 'array'],
            'coat_colors.*' => ['string', 'max:50'],

            // Neutered
            'neutered' => ['nullable', 'boolean'],
            'is_neutered' => ['nullable', 'in:yes,no,in_progress'],
            'microchip_number' => ['nullable', 'string', 'max:100'],

            // Health basics
            'blood_type' => ['nullable', 'string', 'max:50'],
            'allergies' => ['nullable'],
            'chronic_diseases' => ['nullable'],
            'current_medications' => ['nullable'],

            // Feeding
            'food_types' => ['nullable', 'array'],
            'food_types.*' => ['string', 'max:50'],
            'food_brand' => ['nullable', 'string', 'max:150'],
            'dietary_restrictions' => ['nullable', 'array'],
            'dietary_restrictions.*' => ['string', 'max:100'],
            'food_allergies' => ['nullable', 'array'],
            'food_allergies.*' => ['string', 'max:100'],
            'food_allergies_other' => ['nullable', 'string', 'max:500'],

            // Health (structured)
            'chronic_conditions' => ['nullable', 'array'],
            'chronic_conditions.*' => ['string', 'max:100'],
            'surgeries' => ['nullable', 'array'],
            'previous_hospitalizations' => ['nullable', 'boolean'],

            // Behavior
            'temperament' => ['nullable', 'array'],
            'temperament.*' => ['string', 'max:100'],
            'behavior_notes' => ['nullable', 'string', 'max:1000'],
            'social_with' => ['nullable', 'array'],
            'social_with.*' => ['string', 'max:100'],

            // Exercise
            'does_exercise' => ['nullable', 'in:yes,no'],
            'exercise_types' => ['nullable', 'array'],
            'exercise_types.*' => ['string', 'max:50'],
            'exercise_frequency' => ['nullable', 'string', 'max:30'],
            'daily_walk' => ['nullable', 'boolean'],
            'docile_with_strangers' => ['nullable', 'in:yes,no,depends'],
            'docile_with_animals' => ['nullable', 'in:yes,no,depends'],

            // Notes & media
            'notes' => ['nullable', 'string', 'max:2000'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,heic', 'max:5120'],

            // Nested collections (handled by controller after validation).
            'medications' => ['nullable', 'array'],
            'medications.*.name' => ['required_with:medications.*', 'string', 'max:150'],
            'medications.*.dosage' => ['nullable', 'string', 'max:100'],
            'medications.*.frequency' => ['nullable', 'string', 'max:100'],
            'vaccines' => ['nullable', 'array'],
            'vaccines.*.name' => ['required_with:vaccines.*', 'string', 'max:150'],
            'vaccines.*.applied_date' => ['nullable', 'date'],
            'vaccines.*.next_dose_date' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'O nome do pet e obrigatorio.',
            'species.required' => 'A especie e obrigatoria.',
            'species.in' => 'Especie invalida.',
            'gender.required' => 'O sexo e obrigatorio.',
            'gender.in' => 'Sexo invalido.',
            'birth_date.before_or_equal' => 'A data de nascimento nao pode ser no futuro.',
            'weight.min' => 'O peso deve ser um valor positivo.',
        ];
    }
}
