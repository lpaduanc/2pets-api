<?php

namespace App\Http\Controllers\Api\Public;

use App\DataTransferObjects\SearchFiltersDTO;
use App\Enums\ProfessionalType;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProfessionalSearchResource;
use App\Models\User;
use App\Services\Search\GeoLocationService;
use App\Services\Search\ProfessionalSearchService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SearchController extends Controller
{
    public function __construct(
        private readonly ProfessionalSearchService $searchService,
        private readonly GeoLocationService $geoLocationService,
    ) {}

    public function search(Request $request): AnonymousResourceCollection
    {
        $validated = $this->validateSearchRequest($request);

        $filters = SearchFiltersDTO::fromRequest($validated);

        // Use cursor pagination for infinite scroll when ?cursor= is present
        if ($request->has('cursor')) {
            $results = $this->searchService->searchCursor($filters);
        } else {
            $results = $this->searchService->search($filters);
        }

        return ProfessionalSearchResource::collection($results);
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

        return ProfessionalSearchResource::collection($results);
    }

    public function featured(Request $request): AnonymousResourceCollection
    {
        $query = User::query()
            ->where('role', 'professional')
            ->where('profile_completed', true)
            ->where('registration_status', 'approved')
            ->where('is_suspended', false)
            ->whereHas('professional', fn ($q) => $q->where('is_featured', true))
            ->with(['professional', 'professional.services']);

        $this->applyFeaturedDistance($query, $request);

        return ProfessionalSearchResource::collection($query->limit(10)->get());
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

    public function categories(): JsonResponse
    {
        return response()->json([
            'professional_types' => $this->getProfessionalTypes(),
            'service_categories' => $this->getServiceCategories(),
        ]);
    }

    private function validateSearchRequest(Request $request): array
    {
        return $request->validate([
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'radius_km' => 'nullable|integer|min:1|max:100',
            'professional_type' => 'nullable|string',
            'service_category' => 'nullable|string',
            'min_price' => 'nullable|numeric|min:0',
            'max_price' => 'nullable|numeric|min:0',
            'min_rating' => 'nullable|numeric|min:0|max:5',
            'query' => 'nullable|string|max:255',
            'sort_by' => 'nullable|string|in:distance,rating,relevance,price_low,price_high',
            'per_page' => 'nullable|integer|min:1|max:50',
            'available_now' => 'nullable|boolean',
            'page' => 'nullable|integer|min:1',
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

    private function getServiceCategories(): array
    {
        return [
            ['value' => 'consultation', 'label' => 'Consulta'],
            ['value' => 'emergency', 'label' => 'Emergência'],
            ['value' => 'surgery', 'label' => 'Cirurgia'],
            ['value' => 'vaccination', 'label' => 'Vacinação'],
            ['value' => 'grooming', 'label' => 'Banho e Tosa'],
            ['value' => 'training', 'label' => 'Adestramento'],
            ['value' => 'boarding', 'label' => 'Hospedagem'],
            ['value' => 'laboratory', 'label' => 'Exames Laboratoriais'],
            ['value' => 'imaging', 'label' => 'Exames de Imagem'],
            ['value' => 'dental', 'label' => 'Odontologia'],
        ];
    }
}
