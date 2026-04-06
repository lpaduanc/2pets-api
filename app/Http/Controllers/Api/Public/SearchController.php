<?php

namespace App\Http\Controllers\Api\Public;

use App\DataTransferObjects\SearchFiltersDTO;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProfessionalSearchResource;
use App\Services\Search\ProfessionalSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SearchController extends Controller
{
    public function __construct(
        private readonly ProfessionalSearchService $searchService
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
        $query = \App\Models\User::query()
            ->where('role', 'professional')
            ->where('profile_completed', true)
            ->where('registration_status', 'approved')
            ->where('is_suspended', false)
            ->whereHas('professional', fn ($q) => $q->where('is_featured', true))
            ->with(['professional', 'professional.services']);

        if ($request->has('latitude') && $request->has('longitude')) {
            $lat = (float) $request->latitude;
            $lng = (float) $request->longitude;
            $query->selectRaw(
                "users.*, ST_Distance(location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) / 1000 AS distance_km",
                [$lng, $lat]
            )->orderByRaw('distance_km ASC NULLS LAST');
        } else {
            $query->select('users.*')
                ->selectRaw('NULL::double precision AS distance_km');
        }

        return ProfessionalSearchResource::collection($query->limit(10)->get());
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
        ]);
    }

    private function getProfessionalTypes(): array
    {
        return [
            ['value' => 'veterinarian', 'label' => 'Veterinário'],
            ['value' => 'clinic', 'label' => 'Clínica Veterinária'],
            ['value' => 'petshop', 'label' => 'Pet Shop'],
            ['value' => 'groomer', 'label' => 'Banho e Tosa'],
            ['value' => 'trainer', 'label' => 'Adestrador'],
            ['value' => 'pet_sitter', 'label' => 'Pet Sitter'],
            ['value' => 'daycare', 'label' => 'Creche/Hotel'],
            ['value' => 'laboratory', 'label' => 'Laboratório'],
            ['value' => 'pharmacy', 'label' => 'Farmácia Veterinária'],
        ];
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
