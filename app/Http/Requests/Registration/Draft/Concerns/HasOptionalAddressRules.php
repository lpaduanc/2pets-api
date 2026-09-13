<?php

namespace App\Http\Requests\Registration\Draft\Concerns;

/**
 * Mesmos 7 campos de `HasAddressRules` (conclusão de cadastro), mas `sometimes|nullable` em
 * vez de `required` — rascunho é salvo progressivamente, o usuário pode não ter chegado no
 * passo de endereço ainda. `sometimes` já basta para pular a regra quando a chave não vem no
 * payload; o middleware global `ConvertEmptyStringsToNull` cuida do caso "campo apagado no
 * formulário" (string vazia vira `null` antes de chegar aqui), então `nullable` cobre os dois.
 */
trait HasOptionalAddressRules
{
    /** @return array<string, list<string>> */
    protected function optionalAddressRules(): array
    {
        return [
            'address' => ['sometimes', 'nullable', 'string'],
            'number' => ['sometimes', 'nullable', 'string'],
            'complement' => ['sometimes', 'nullable', 'string'],
            'neighborhood' => ['sometimes', 'nullable', 'string'],
            'city' => ['sometimes', 'nullable', 'string'],
            'state' => ['sometimes', 'nullable', 'string', 'size:2'],
            'zip_code' => ['sometimes', 'nullable', 'string'],
        ];
    }

    /** @return array<string, string> */
    protected function optionalAddressMessages(): array
    {
        return [
            'state.size' => 'O estado deve ser a sigla com 2 letras (ex: SP).',
        ];
    }
}
