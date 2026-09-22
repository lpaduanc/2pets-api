<?php

namespace App\Services\Import;

use App\Contracts\Import\ImportRowExecutor;
use App\Contracts\Import\ImportRowValidator;
use App\Enums\Import\ImportEntity;
use App\Exceptions\Import\ImportEntityNotSupportedException;
use Illuminate\Contracts\Container\Container;

/**
 * Único ponto de extensão de validador/executor por entidade (item 26 do backlog
 * gap-simplesvet) — mesmo papel que `App\Support\Catalog\CatalogTypeRegistry` cumpre para o
 * item 23: adicionar uma entidade nova é uma linha em `VALIDATORS`/`EXECUTORS`, nunca um novo
 * `match` espalhado em `DataImportService`/`ImportExecutionService` (OCP).
 *
 * Resolve via o container em vez de injetar as quatro implementações no construtor de quem
 * chama — `DataImportService` já está no teto de 5 dependências (dívida pré-existente,
 * documentada em `.claude/agent-memory/backend-specialist/gap-simplesvet-lote-22-23-21-26.md`)
 * e ganhar uma classe por entidade nova estouraria o limite de novo a cada rodada.
 */
final class ImportEntityHandlerRegistry
{
    /**
     * @var array<string, class-string<ImportRowValidator>>
     */
    private const VALIDATORS = [
        'clients' => ClientImportValidator::class,
        'pets' => PetImportValidator::class,
        'products' => ProductImportValidator::class,
        'vaccinations' => VaccinationImportValidator::class,
    ];

    /**
     * @var array<string, class-string<ImportRowExecutor>>
     */
    private const EXECUTORS = [
        'clients' => ClientImportExecutor::class,
        'pets' => PetImportExecutor::class,
        'products' => ProductImportExecutor::class,
        'vaccinations' => VaccinationImportExecutor::class,
    ];

    public function __construct(private readonly Container $container) {}

    /**
     * Consultado por `ImportEntity::isSupported()` — não requer resolução de dependência,
     * só a presença da entidade no mapa, para o enum poder responder sem o container.
     */
    public static function supports(ImportEntity $entity): bool
    {
        return isset(self::VALIDATORS[$entity->value]);
    }

    public function validatorFor(ImportEntity $entity): ImportRowValidator
    {
        return $this->container->make($this->classFor(self::VALIDATORS, $entity));
    }

    public function executorFor(ImportEntity $entity): ImportRowExecutor
    {
        return $this->container->make($this->classFor(self::EXECUTORS, $entity));
    }

    /**
     * @param  array<string, class-string>  $map
     * @return class-string
     */
    private function classFor(array $map, ImportEntity $entity): string
    {
        return $map[$entity->value] ?? throw new ImportEntityNotSupportedException($entity);
    }
}
