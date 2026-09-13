<?php

namespace App\Exceptions;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Contrato único de "já existe cadastro com esses dados", usado nos dois pontos onde uma
 * colisão de documento pode ser detectada:
 *   - `QueryException` SQLSTATE 23505 (violação de índice único no banco — cobre a race
 *     condition que sobrevive mesmo com `Rule::unique` no Form Request, ver
 *     `bootstrap/app.php`);
 *   - qualquer código de aplicação que precise sinalizar a mesma duplicidade antes de tentar
 *     a escrita (ver `RegistrationDraftController`).
 *
 * O mapa de constraint → campo cobre só os documentos de cadastro (onda 1 do plano de
 * correção); constraint não mapeada cai em `null` e quem chamou decide o que fazer — nunca
 * vaza nome de constraint nem SQL para o cliente.
 */
final class DuplicateRegistrationException extends RuntimeException
{
    private const MESSAGE = 'Já existe um cadastro com esses dados.';

    /**
     * @var array<string, array{field: string, message: string}>
     */
    private const CONSTRAINT_FIELDS = [
        'users_email_unique' => ['field' => 'email', 'message' => 'Este e-mail já está cadastrado.'],
        'users_cpf_unique' => ['field' => 'cpf', 'message' => 'Este CPF já está cadastrado.'],
        'professionals_cnpj_unique' => ['field' => 'cnpj', 'message' => 'Este CNPJ já está cadastrado.'],
        'professionals_crmv_unique' => ['field' => 'crmv', 'message' => 'Este CRMV já está cadastrado.'],
        'companies_cnpj_unique' => ['field' => 'cnpj', 'message' => 'Este CNPJ já está cadastrado.'],
    ];

    private function __construct(
        private readonly string $field,
        private readonly string $fieldMessage,
    ) {
        parent::__construct(self::MESSAGE);
    }

    public static function forField(string $field, string $message): self
    {
        return new self($field, $message);
    }

    /**
     * Só retorna instância quando a exceção é mesmo uma violação de unicidade (SQLSTATE
     * 23505) de uma das constraints conhecidas. Qualquer outra `QueryException` (FK, CHECK,
     * NOT NULL) devolve `null` para o chamador seguir com o tratamento padrão do framework.
     */
    public static function fromQueryException(QueryException $exception): ?self
    {
        if ($exception->getCode() !== '23505') {
            return null;
        }

        foreach (self::CONSTRAINT_FIELDS as $constraint => $definition) {
            if (str_contains($exception->getMessage(), $constraint)) {
                return new self($definition['field'], $definition['message']);
            }
        }

        return null;
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => self::MESSAGE,
            'errors' => [$this->field => [$this->fieldMessage]],
            'duplicate' => ['fields' => [$this->field], 'action' => 'login'],
        ], 422);
    }
}
