<?php

namespace App\Http\Requests\Professional;

use App\Enums\PetSpecies;
use App\Http\Requests\Pet\StorePetRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * `POST professional/clients/{id}/pets` — o pro cadastra pet para um cliente que já existe
 * (recepção, pet shop e veterinário). Mesmas regras do cadastro do tutor (`StorePetRequest`)
 * para os campos mínimos; `sex` é aceito como apelido de `gender`. `species` segue obrigatório,
 * como no cadastro do tutor e no paciente novo: a coluna é NOT NULL e espécie decide filtros
 * clínicos (vacina, porte).
 */
class StoreClientPetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('sex') && ! $this->has('gender')) {
            $this->merge(['gender' => $this->input('sex')]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tutorRules = Arr::only((new StorePetRequest)->rules(), ['name', 'breed', 'breed_id', 'birth_date', 'weight']);

        return $tutorRules + [
            'species' => ['required', Rule::enum(PetSpecies::class)],
            'gender' => ['nullable', 'in:male,female,unknown'],
            'sex' => ['nullable', 'in:male,female,unknown'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => 'nome', 'species' => 'espécie', 'gender' => 'sexo', 'sex' => 'sexo', 'birth_date' => 'data de nascimento', 'weight' => 'peso'];
    }
}
