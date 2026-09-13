<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Formata a falha de `Rule::unique` no MESMO contrato que `DuplicateRegistrationException`
 * usa para a colisão só detectada no banco (race condition, ver `bootstrap/app.php`). Sem
 * isto o app recebe dois formatos diferentes para o mesmo problema: aqui o `unique` já pega
 * a duplicata na validação; lá é a constraint do banco que pega o que passou por uma corrida
 * entre duas requisições simultâneas.
 *
 * Quem usa a trait declara, em `duplicateFields()`, quais campos do próprio Form Request
 * correspondem a documento (cpf/cnpj/crmv/email) — os únicos para os quais faz sentido
 * oferecer "faça login" como próximo passo.
 */
trait FormatsDuplicateFieldErrors
{
    /** @return list<string> */
    protected function duplicateFields(): array
    {
        return [];
    }

    protected function failedValidation(Validator $validator): void
    {
        $errors = $validator->errors();
        $duplicateFields = $this->fieldsThatFailedUnique($validator);

        if ($duplicateFields === []) {
            throw new HttpResponseException(response()->json([
                'message' => 'Os dados enviados são inválidos.',
                'errors' => $errors->messages(),
            ], 422));
        }

        throw new HttpResponseException(response()->json([
            'message' => 'Já existe um cadastro com esses dados.',
            'errors' => $errors->messages(),
            'duplicate' => ['fields' => $duplicateFields, 'action' => 'login'],
        ], 422));
    }

    /**
     * `Validator::failed()` devolve `[campo => [NomeDaRegra => parâmetros]]` — é o único jeito
     * de saber QUAL regra reprovou um campo (`errors()` só devolve a mensagem já traduzida).
     *
     * @return list<string>
     */
    private function fieldsThatFailedUnique(Validator $validator): array
    {
        $failed = $validator->failed();

        return array_values(array_filter(
            $this->duplicateFields(),
            fn (string $field): bool => array_key_exists('Unique', $failed[$field] ?? [])
        ));
    }
}
