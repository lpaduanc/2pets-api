<?php

namespace App\Services\Crm;

use App\Models\ClientRelationshipProfile;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use Illuminate\Database\Eloquent\Builder;

/**
 * Traduz a definição JSON de um segmento em query sobre `client_relationship_profiles` —
 * contrato `docs/gap-simplesvet/specs/18-segmentacao-clientes-spec.md`. Consumido por
 * `POST clients/search`, `client-segments` (salvo/reutilizável) e, depois, por
 * `message-campaigns/preview` (17) e o BI (20).
 *
 * Formato de `$definition` (todas as chaves opcionais):
 * ```
 * {
 *   "lifecycle_stage": ["quiet_3_6m", "churned_3_5y"],
 *   "abc_class": ["A", "B"],
 *   "client_origin_id": [1, 2],
 *   "tag_id": [5],
 *   "species": "dog",
 *   "pathology": "diabetes",
 *   "include_archived": false
 * }
 * ```
 */
final class ClientSegmentQueryBuilder
{
    public function __construct(
        private readonly CommercialScopeResolver $scopeResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $definition
     * @return Builder<ClientRelationshipProfile>
     */
    public function build(User $professional, array $definition): Builder
    {
        $query = $this->scopeResolver->scopeQuery(ClientRelationshipProfile::query(), $professional)
            ->with('client', 'clientOrigin');

        $this->applyArchivedFilter($query, $definition);
        $this->applyListFilter($query, 'lifecycle_stage', $definition);
        $this->applyListFilter($query, 'abc_class', $definition);
        $this->applyListFilter($query, 'client_origin_id', $definition);
        $this->applyTagFilter($query, $definition);
        $this->applyPetFilters($query, $definition);

        return $query;
    }

    /** @param  Builder<ClientRelationshipProfile>  $query @param  array<string, mixed>  $definition */
    private function applyArchivedFilter(Builder $query, array $definition): void
    {
        if (($definition['include_archived'] ?? false) !== true) {
            $query->notArchived();
        }
    }

    /**
     * Filtro genérico "coluna IN lista" — usado por `lifecycle_stage`, `abc_class` e
     * `client_origin_id`, os três só diferem no nome da coluna/chave.
     *
     * @param  Builder<ClientRelationshipProfile>  $query
     * @param  array<string, mixed>  $definition
     */
    private function applyListFilter(Builder $query, string $key, array $definition): void
    {
        $values = $definition[$key] ?? null;

        if (! empty($values)) {
            $query->whereIn($key, $values);
        }
    }

    /** @param  Builder<ClientRelationshipProfile>  $query @param  array<string, mixed>  $definition */
    private function applyTagFilter(Builder $query, array $definition): void
    {
        $tagIds = $definition['tag_id'] ?? null;

        if (empty($tagIds)) {
            return;
        }

        $query->whereHas('client.tags', fn (Builder $tags) => $tags->whereIn('tags.id', $tagIds));
    }

    /** @param  Builder<ClientRelationshipProfile>  $query @param  array<string, mixed>  $definition */
    private function applyPetFilters(Builder $query, array $definition): void
    {
        $species = $definition['species'] ?? null;
        $pathology = $definition['pathology'] ?? null;

        if ($species === null && $pathology === null) {
            return;
        }

        $query->whereHas('client.pets', function (Builder $pets) use ($species, $pathology): void {
            $this->constrainPetsBySpecies($pets, $species);
            $this->constrainPetsByPathology($pets, $pathology);
        });
    }

    private function constrainPetsBySpecies(Builder $pets, ?string $species): void
    {
        if ($species !== null) {
            $pets->where('species', $species);
        }
    }

    /**
     * Filtro por SUBSTRING, nunca exato — `Pet.chronic_diseases`/`chronic_conditions` são
     * texto livre (regra de negócio 7 da spec). A UI precisa apresentar isto como
     * "contém: {termo}", nunca como filtro preciso.
     */
    private function constrainPetsByPathology(Builder $pets, ?string $substring): void
    {
        if ($substring === null) {
            return;
        }

        $pets->where(fn (Builder $scoped) => $scoped
            ->whereRaw('chronic_diseases::text ILIKE ?', ['%'.$substring.'%'])
            ->orWhereRaw('chronic_conditions::text ILIKE ?', ['%'.$substring.'%']));
    }
}
