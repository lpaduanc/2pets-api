<?php

namespace App\Services\Search;

use App\DataTransferObjects\SearchFiltersDTO;
use App\Enums\ProfessionalType;
use App\Support\Registration\ProfessionalCapabilityRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Raio de atendimento do profissional VOLANTE: o tutor precisa estar dentro do
 * `service_radius_km` dele, além de o profissional estar dentro do raio da busca.
 *
 * Só volantes (decisão de produto): clínica, petshop e afins são ponto fixo — o tutor vai
 * até eles, e o raio da própria busca já decide até onde ele aceita ir. Volante sem raio
 * informado não é filtrado (a busca não esconde quem não preencheu).
 *
 * ── Por que `NOT EXISTS` correlacionado aqui, contra a regra de `applyProfessionalFilters`
 * O raio muda POR LINHA e depende de `users.location`, então não há como descorrelacionar.
 * A consulta é barata porque roda só sobre as linhas que o `ST_DWithin` do raio da busca
 * (índice GIST parcial) já podou: vira `Nested Loop Anti Join` com `Index Scan` em
 * `idx_professionals_user_id`. Medido (2026-09-24, base de dev com 45 mil profissionais):
 * pior caso — 50 km no centro de SP, 27 mil candidatos — foi de 61 ms para 71 ms; com
 * termo + tipo + nota a poda é tão grande que a diferença some.
 */
final class ServiceRadiusFilter
{
    public function __construct(private readonly GeoLocationService $geoLocationService) {}

    public function apply(Builder $query, SearchFiltersDTO $filters): void
    {
        if (! $filters->hasLocation()) {
            return;
        }

        $withinServiceArea = $this->geoLocationService->dWithinColumnRadiusExpression(
            'users.location',
            $filters->latitude,
            $filters->longitude,
            'professionals.service_radius_km'
        );

        $query->whereNotExists(function (QueryBuilder $sub) use ($withinServiceArea): void {
            $sub->selectRaw('1')
                ->from('professionals')
                ->whereColumn('professionals.user_id', 'users.id')
                ->whereIn('professionals.professional_type', $this->mobileTypeValues())
                ->whereNotNull('professionals.service_radius_km')
                ->whereRaw("NOT {$withinServiceArea['sql']}", $withinServiceArea['bindings']);
        });
    }

    /**
     * @return list<string>
     */
    private function mobileTypeValues(): array
    {
        return array_map(
            fn (ProfessionalType $type): string => $type->value,
            ProfessionalCapabilityRegistry::mobileTypes()
        );
    }
}
