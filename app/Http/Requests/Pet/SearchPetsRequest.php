<?php

namespace App\Http\Requests\Pet;

use App\DataTransferObjects\Cpf;
use App\DataTransferObjects\PetSearchFilters;
use App\Models\Pet;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

class SearchPetsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('search', Pet::class) ?? false;
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException('Somente veterinários podem buscar pets pelo CPF do tutor.');
    }

    /**
     * O app envia o CPF com máscara; a coluna guarda dígitos e é indexada por igualdade.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('tutor_cpf')) {
            return;
        }

        $this->merge(['tutor_cpf' => Cpf::stripMask($this->input('tutor_cpf'))]);
    }

    public function rules(): array
    {
        return [
            'tutor_cpf' => ['required_without:microchip_number', 'nullable', 'digits:'.Cpf::DIGIT_COUNT],
            'microchip_number' => ['required_without:tutor_cpf', 'nullable', 'string', 'min:3', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.PetSearchFilters::MAX_PER_PAGE],
        ];
    }

    public function messages(): array
    {
        return [
            'tutor_cpf.required_without' => 'Informe o CPF do tutor ou o número do microchip.',
            'tutor_cpf.digits' => 'CPF deve conter 11 dígitos.',
            'microchip_number.required_without' => 'Informe o número do microchip ou o CPF do tutor.',
            'microchip_number.min' => 'O número do microchip é curto demais para uma busca.',
            'per_page.max' => 'O limite máximo por página é '.PetSearchFilters::MAX_PER_PAGE.'.',
        ];
    }

    public function toFilters(): PetSearchFilters
    {
        return PetSearchFilters::fromValidated($this->validated());
    }

    public function queryParameters(): array
    {
        return [
            'tutor_cpf' => ['description' => 'CPF do tutor, com ou sem máscara. Busca exata.'],
            'microchip_number' => ['description' => 'Número do microchip do pet. Busca exata.'],
            'per_page' => ['description' => 'Itens por página (máximo '.PetSearchFilters::MAX_PER_PAGE.').'],
        ];
    }
}
