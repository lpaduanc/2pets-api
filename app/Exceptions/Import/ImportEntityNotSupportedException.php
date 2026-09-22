<?php

namespace App\Exceptions\Import;

use App\Enums\Import\ImportEntity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Item 26 do backlog gap-simplesvet — só as entidades cadastradas em
 * `App\Services\Import\ImportEntityHandlerRegistry` têm parser/validador/executor de verdade
 * (`ImportEntity::isSupported()`). Recusa explícita com 422 em vez de aceitar o upload e
 * fingir suportar uma entidade sem processamento real por trás.
 */
final class ImportEntityNotSupportedException extends RuntimeException
{
    public function __construct(private readonly ImportEntity $entity)
    {
        parent::__construct(sprintf(
            'Importação de "%s" ainda não está disponível nesta versão.',
            $entity->value,
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
