<?php

namespace App\Http\Controllers\Api\Public;

use App\DataTransferObjects\SearchFiltersDTO;
use App\Enums\ProfessionalType;
use App\Enums\ServiceCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Search\PublicProfessionalSearchRequest;
use App\Http\Resources\ProfessionalSearchCardResource;
use App\Http\Resources\ProfessionalSearchCollection;
use App\Models\User;
use App\Services\Search\GeoLocationService;
use App\Services\Search\ProfessionalSearchService;
use App\Services\Search\SearchConceptPresenter;
use App\Services\Search\SearchResultMetaBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SearchController extends Controller
{
    public function __construct(
        private readonly ProfessionalSearchService $searchService,
        private readonly GeoLocationService $geoLocationService,
        private readonly SearchResultMetaBuilder $searchResultMetaBuilder,
        private readonly SearchConceptPresenter $searchConceptPresenter,
    ) {}

    public function search(PublicProfessionalSearchRequest $request): ProfessionalSearchCollection
    {
        $filters = SearchFiltersDTO::fromRequest($request->validated());

        // Cursor pagination serve o scroll infinito quando `?cursor=` está presente.
        return $request->has('cursor')
            ? $this->cursorResults($filters)
            : $this->pagedResults($filters);
    }

    private function pagedResults(SearchFiltersDTO $filters): ProfessionalSearchCollection
    {
        $results = $this->searchService->search($filters);

        return ProfessionalSearchCollection::make($results)
            ->withSearchMeta($this->searchResultMetaBuilder->forPage($filters, $results));
    }

    private function cursorResults(SearchFiltersDTO $filters): ProfessionalSearchCollection
    {
        $results = $this->searchService->searchCursor($filters);

        return ProfessionalSearchCollection::make($results)
            ->withSearchMeta($this->searchResultMetaBuilder->forCursor($filters));
    }

    public function nearby(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'radius_km' => 'nullable|integer|min:1|max:100',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        $filters = SearchFiltersDTO::fromRequest([
            ...$validated,
            'sort_by' => 'distance',
            'per_page' => $validated['limit'] ?? 10,
        ]);

        $results = $this->searchService->search($filters);

        return ProfessionalSearchCardResource::collection($results);
    }

    public function featured(Request $request): AnonymousResourceCollection
    {
        $query = User::query()
            ->visibleProfessional()
            ->whereHas('professional', fn ($q) => $q->where('is_featured', true))
            ->with(['professional', 'professional.services']);

        $this->applyFeaturedDistance($query, $request);

        return ProfessionalSearchCardResource::collection($query->limit(10)->get());
    }

    /**
     * Mesma expressão PostGIS usada por `ProfessionalSearchService::buildBaseQuery()`,
     * delegada a `GeoLocationService` em vez de duplicada aqui — duas implementações da
     * mesma expressão divergem cedo ou tarde e uma delas para de casar o índice parcial
     * GIST da Fase 4 (`idx_users_visible_professional_location`) em silêncio.
     */
    private function applyFeaturedDistance(Builder $query, Request $request): void
    {
        if (! $request->has('latitude') || ! $request->has('longitude')) {
            $query->select('users.*')->selectRaw('NULL::double precision AS distance_km');

            return;
        }

        $distance = $this->geoLocationService->distanceExpression(
            'users.location',
            (float) $request->latitude,
            (float) $request->longitude
        );

        $query->selectRaw(
            "users.*, ({$distance['sql']}) / 1000 AS distance_km",
            $distance['bindings']
        )->orderByRaw('distance_km ASC NULLS LAST');
    }

    /**
     * Catálogo dos filtros da busca. As três listas são DERIVADAS — enum, enum e catálogo —
     * e nenhuma delas é redigitada aqui.
     *
     * `specialties` entrou porque a UI da busca tinha 8 especialidades chumbadas no
     * `SearchPage.vue` enquanto a base grava 21: as duas MAIORES ("Diagnóstico por Imagem",
     * 836 profissionais, e "Patologia Clínica", 829) não eram filtráveis por ninguém. É o
     * mesmo furo que a espécie tinha (6 opções para 7 valores gravados) — lista de filtro
     * mantida à mão no frontend perde o passo do catálogo e some com resultado real.
     */
    public function categories(): JsonResponse
    {
        return response()->json([
            'professional_types' => $this->getProfessionalTypes(),
            'service_categories' => $this->getServiceCategories(),
            'specialties' => $this->searchConceptPresenter->specialtyFilterOptions(),
        ]);
    }

    /**
     * Os 7 tipos canônicos vêm de `ProfessionalType`, nunca de uma lista redigitada —
     * ver `docs/taxonomia-professional-type.md`. `pet_sitter`/`pharmacy`/`other` ficam
     * fora até existir cadastro real (oferecê-los aqui sempre devolveria zero resultados).
     */
    private function getProfessionalTypes(): array
    {
        return array_map(
            fn (ProfessionalType $type): array => ['value' => $type->value, 'label' => $type->label()],
            ProfessionalType::cases()
        );
    }

    /**
     * Derivado de `ServiceCategory`, nunca de lista redigitada — as 15 categorias do enum
     * são agora exatamente as 15 que o CHECK de `services.category` aceita (migration
     * `2026_09_22_100000`). Antes daquela migration esta lista oferecia 10 opções das quais
     * 6 eram inalcançáveis: o valor não podia ser gravado, então o filtro sempre voltava
     * vazio. `OTHER` fica fora por decisão de produto do `pet-business-specialist`
     * ("filtro que devolve zero resultado sempre é pior que filtro ausente", e nenhum tipo
     * de profissional tem `OTHER` em `service_categories`).
     */
    private function getServiceCategories(): array
    {
        return array_values(array_map(
            fn (ServiceCategory $category): array => ['value' => $category->value, 'label' => $category->label()],
            array_filter(
                ServiceCategory::cases(),
                fn (ServiceCategory $category): bool => $category !== ServiceCategory::OTHER,
            ),
        ));
    }
}
