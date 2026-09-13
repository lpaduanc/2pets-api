<?php

namespace App\Http\Requests\Registration\Concerns;

/**
 * Regras de endereço compartilhadas pelos quatro fluxos de conclusão de cadastro (tutor,
 * veterinário, profissional genérico e empresa parceira). Os quatro pediam exatamente os
 * mesmos sete campos, copiados e colados — extraído aqui para não divergir no futuro.
 */
trait HasAddressRules
{
    /** @return array<string, list<string>> */
    protected function addressRules(): array
    {
        return [
            'address' => ['required', 'string'],
            'number' => ['required', 'string'],
            'complement' => ['nullable', 'string'],
            'neighborhood' => ['required', 'string'],
            'city' => ['required', 'string'],
            'state' => ['required', 'string', 'size:2'],
            'zip_code' => ['required', 'string'],
        ];
    }

    /** @return array<string, string> */
    protected function addressMessages(): array
    {
        return [
            'address.required' => 'O endereço é obrigatório.',
            'number.required' => 'O número é obrigatório.',
            'neighborhood.required' => 'O bairro é obrigatório.',
            'city.required' => 'A cidade é obrigatória.',
            'state.required' => 'O estado é obrigatório.',
            'state.size' => 'O estado deve ser a sigla com 2 letras (ex: SP).',
            'zip_code.required' => 'O CEP é obrigatório.',
        ];
    }
}
