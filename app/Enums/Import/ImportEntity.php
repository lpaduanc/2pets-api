<?php

namespace App\Enums\Import;

use App\Services\Import\ImportEntityHandlerRegistry;

/**
 * Entidade importável (item 26 do backlog gap-simplesvet). `isSupported()` distingue o
 * shape do banco (as 5 entidades do documento original) do que esta rodada de fato executa
 * — `clients`, `pets`, `products` e `vaccinations` (`services` fica para uma rodada futura,
 * ver `docs/gap-simplesvet/contratos/26-contrato-api.md`). Importar fora de ordem (`pets`
 * antes de `clients` existir) é bloqueado por `ImportDependencyOrderGuard`, não por este enum.
 */
enum ImportEntity: string
{
    case CLIENTS = 'clients';
    case PETS = 'pets';
    case PRODUCTS = 'products';
    case SERVICES = 'services';
    case VACCINATIONS = 'vaccinations';

    /**
     * A entidade tem `ImportRowValidator`/`ImportRowExecutor` de verdade cadastrados em
     * `ImportEntityHandlerRegistry` — única fonte de verdade, para não haver uma lista aqui e
     * outra lá que um dia divergem. Entidade sem handler entra no enum só para completar o
     * shape do modelo de dados da spec; `DataImportService` recusa com 422 explícito.
     */
    public function isSupported(): bool
    {
        return ImportEntityHandlerRegistry::supports($this);
    }
}
