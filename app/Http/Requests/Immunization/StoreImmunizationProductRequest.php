<?php

namespace App\Http\Requests\Immunization;

use App\Enums\ImmunizationGroup;
use App\Enums\PetSpecies;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreImmunizationProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'group' => ['required', Rule::in(ImmunizationGroup::values())],
            'manufacturer' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'legally_required' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
            'species' => ['required', 'array', 'min:1'],
            'species.*' => [Rule::in(PetSpecies::values())],
        ];
    }
}
