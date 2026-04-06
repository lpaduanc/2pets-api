<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Public endpoint
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'phone' => ['required', 'string', 'max:20'],
            'user_type' => ['required', 'in:tutor,vet,clinic,laboratory,petshop,pet_hotel,grooming,training,company'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'additional_data' => ['array', 'nullable'],
            'additional_data.cnpj' => ['nullable', 'string', 'max:20'],
            'additional_data.employee_count' => ['nullable', 'string', 'max:50'],
            'additional_data.message' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'O nome e obrigatorio.',
            'email.required' => 'O e-mail e obrigatorio.',
            'email.unique' => 'Este e-mail ja esta cadastrado.',
            'phone.required' => 'O telefone e obrigatorio.',
            'user_type.required' => 'O tipo de usuario e obrigatorio.',
            'user_type.in' => 'Tipo de usuario invalido.',
            'password.required' => 'A senha e obrigatoria.',
            'password.min' => 'A senha deve ter pelo menos 8 caracteres.',
            'password.confirmed' => 'As senhas nao conferem.',
        ];
    }
}
