<?php

namespace App\Exceptions\Import;

use App\Enums\Import\ImportEntity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Item 26 do backlog gap-simplesvet, regra 1: "ordem de dependência é bloqueante, não
 * sugestão" — catálogos (23) → clientes → pets → produtos → histórico (vacinas). Importar uma
 * entidade antes de a dependência anterior da cadeia existir devolve 422 explicando a ordem
 * correta — nunca uma tentativa de resolver automaticamente por trás.
 */
final class ImportOutOfOrderException extends RuntimeException
{
    /**
     * @var array<string, array{singular: string, plural: string}>
     */
    private const DEPENDENCY_LABELS = [
        'clients' => ['singular' => 'cliente', 'plural' => 'clientes'],
        'pets' => ['singular' => 'pet', 'plural' => 'pets'],
    ];

    public function __construct(
        private readonly ImportEntity $entity,
        private readonly ImportEntity $missingDependency = ImportEntity::CLIENTS,
    ) {
        $labels = self::DEPENDENCY_LABELS[$missingDependency->value];

        parent::__construct(sprintf(
            'Não é possível importar "%s" antes de existir ao menos um %s cadastrado. Importe %s primeiro.',
            $entity->value,
            $labels['singular'],
            $labels['plural'],
        ));
    }

    public function report(): bool
    {
        return false;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'entity' => $this->entity->value], 422);
    }
}
