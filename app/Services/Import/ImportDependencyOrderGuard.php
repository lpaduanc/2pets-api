<?php

namespace App\Services\Import;

use App\Enums\Import\ImportEntity;
use App\Models\Pet;
use App\Models\ProfessionalClient;
use Illuminate\Support\Collection;

/**
 * Regra 1 da spec 26: "ordem de dependência é bloqueante, não sugestão" — catálogos (23) →
 * clientes → pets → produtos/serviços → histórico (vacinas). `products` fica fora da cadeia
 * de propósito: é catálogo comercial independente de tutor/pet (nenhum critério de aceite da
 * spec exige o contrário — só os exemplos "pets antes de clientes" e "histórico antes de
 * pets" são explícitos), então importar produtos nunca é bloqueado por esta checagem.
 */
final class ImportDependencyOrderGuard
{
    public function ensureCanImport(ImportEntity $entity, int $professionalId): void
    {
        match ($entity) {
            ImportEntity::PETS => $this->ensureHasClients($entity, $professionalId),
            ImportEntity::VACCINATIONS => $this->ensureHasPets($entity, $professionalId),
            default => null,
        };
    }

    private function ensureHasClients(ImportEntity $entity, int $professionalId): void
    {
        if (! $this->clientIdsOf($professionalId)->isNotEmpty()) {
            throw new ImportOutOfOrderException($entity, ImportEntity::CLIENTS);
        }
    }

    private function ensureHasPets(ImportEntity $entity, int $professionalId): void
    {
        if (! Pet::whereIn('user_id', $this->clientIdsOf($professionalId))->exists()) {
            throw new ImportOutOfOrderException($entity, ImportEntity::PETS);
        }
    }

    /**
     * @return Collection<int, int>
     */
    private function clientIdsOf(int $professionalId): Collection
    {
        return ProfessionalClient::where('professional_id', $professionalId)->pluck('client_id');
    }
}
