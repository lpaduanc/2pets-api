<?php

namespace Tests\Feature;

use App\Models\Professional;
use App\Models\User;
use App\Services\Search\GeoLocationService;
use App\Services\Search\ProfessionalSearchCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Fase 9 do plano de otimizacao: cache do resultado da busca de profissionais por
 * contador de versao + grade geografica adaptativa, guardando so ids (nunca models).
 */
class ProfessionalSearchCacheTest extends TestCase
{
    use RefreshDatabase;

    private function createApprovedProfessional(array $userOverrides = [], array $professionalOverrides = []): User
    {
        $user = User::factory()->professional()->create(array_merge([
            'profile_completed' => true,
            'registration_status' => 'approved',
            'is_suspended' => false,
        ], $userOverrides));

        Professional::factory()->create(array_merge([
            'user_id' => $user->id,
        ], $professionalOverrides));

        return $user;
    }

    /**
     * Verificacao obrigatoria do plano: salvar um `Professional` precisa invalidar a
     * busca (o contador de versao incrementa e a chave antiga deixa de ser servida).
     */
    public function test_saving_a_professional_bumps_the_cache_version_and_invalidates_results(): void
    {
        $user = $this->createApprovedProfessional([], ['professional_type' => 'vet']);

        $firstResponse = $this->getJson('/api/public/search?professional_type=vet');
        $firstResponse->assertOk();
        $this->assertTrue(collect($firstResponse->json('data'))->pluck('id')->contains($user->id));

        $versionBeforeUpdate = Cache::get(ProfessionalSearchCache::VERSION_CACHE_KEY, 0);

        $user->professional->update(['professional_type' => 'petshop']);

        $versionAfterUpdate = Cache::get(ProfessionalSearchCache::VERSION_CACHE_KEY, 0);
        $this->assertGreaterThan($versionBeforeUpdate, $versionAfterUpdate);

        // Mesma chave de filtro (professional_type=vet) — se a chave antiga ainda
        // fosse servida, o profissional (agora petshop) continuaria aparecendo aqui.
        $secondResponse = $this->getJson('/api/public/search?professional_type=vet');
        $secondResponse->assertOk();
        $this->assertFalse(collect($secondResponse->json('data'))->pluck('id')->contains($user->id));
    }

    /**
     * Suspender o `User` profissional (campo relevante para o WHERE da busca) tambem
     * precisa invalidar — nao so mudancas no `Professional`.
     */
    public function test_suspending_the_professional_user_invalidates_the_search_cache(): void
    {
        $user = $this->createApprovedProfessional();

        $firstResponse = $this->getJson('/api/public/search');
        $firstResponse->assertOk();
        $this->assertTrue(collect($firstResponse->json('data'))->pluck('id')->contains($user->id));

        $user->update(['is_suspended' => true]);

        $secondResponse = $this->getJson('/api/public/search');
        $secondResponse->assertOk();
        $this->assertFalse(collect($secondResponse->json('data'))->pluck('id')->contains($user->id));
    }

    /**
     * Verificacao obrigatoria do plano: `distance_km` continua correto para um usuario
     * que nao esta no centro/canto da celula da grade de cache. Os dois pontos de
     * busca abaixo caem DENTRO DA MESMA celula (raio <=5km -> grade de 0,01 grau), o
     * que forca a segunda chamada a reaproveitar os MESMOS ids cacheados da primeira —
     * se `distance_km` viesse do cache (ou fosse calculado a partir do canto da
     * celula), as duas respostas teriam a mesma distancia. Elas nao devem ter.
     */
    public function test_distance_km_is_recalculated_from_real_coordinates_on_cache_hit(): void
    {
        $professionalLatitude = -23.5505;
        $professionalLongitude = -46.6333;

        $user = $this->createApprovedProfessional([
            'latitude' => $professionalLatitude,
            'longitude' => $professionalLongitude,
        ]);

        $searchPointNear = ['latitude' => -23.5510, 'longitude' => -46.6340];
        $searchPointFar = ['latitude' => -23.5540, 'longitude' => -46.6370];

        $geoLocationService = app(GeoLocationService::class);

        $expectedDistanceNear = $geoLocationService->calculateDistanceKm(
            $searchPointNear['latitude'],
            $searchPointNear['longitude'],
            $professionalLatitude,
            $professionalLongitude,
        );
        $expectedDistanceFar = $geoLocationService->calculateDistanceKm(
            $searchPointFar['latitude'],
            $searchPointFar['longitude'],
            $professionalLatitude,
            $professionalLongitude,
        );

        // As distancias reais precisam divergir de verdade para o teste provar algo.
        $this->assertNotEqualsWithDelta($expectedDistanceNear, $expectedDistanceFar, 0.05);

        $responseNear = $this->getJson('/api/public/search?'.http_build_query([
            'latitude' => $searchPointNear['latitude'],
            'longitude' => $searchPointNear['longitude'],
            'radius_km' => 5,
        ]));
        $responseFar = $this->getJson('/api/public/search?'.http_build_query([
            'latitude' => $searchPointFar['latitude'],
            'longitude' => $searchPointFar['longitude'],
            'radius_km' => 5,
        ]));

        $responseNear->assertOk();
        $responseFar->assertOk();

        $itemNear = collect($responseNear->json('data'))->firstWhere('id', $user->id);
        $itemFar = collect($responseFar->json('data'))->firstWhere('id', $user->id);

        $this->assertNotNull($itemNear);
        $this->assertNotNull($itemFar);

        $this->assertEqualsWithDelta(round($expectedDistanceNear, 2), $itemNear['distance_km'], 0.05);
        $this->assertEqualsWithDelta(round($expectedDistanceFar, 2), $itemFar['distance_km'], 0.05);
    }

    /**
     * `page` sai de `request()` lido dentro do service e entra no `SearchFiltersDTO` —
     * garante que a segunda pagina traz um profissional diferente da primeira, sem
     * depender de estado HTTP global escondido dentro do service.
     */
    public function test_pagination_slices_the_cached_ids_without_reading_request_globally(): void
    {
        $firstUser = $this->createApprovedProfessional(['name' => 'Ana Primeira']);
        $secondUser = $this->createApprovedProfessional(['name' => 'Bruno Segundo']);

        // Sem localizacao, `sortByDistance()` cai para `ORDER BY users.name` — deterministico.
        $firstPageResponse = $this->getJson('/api/public/search?per_page=1&page=1');
        $secondPageResponse = $this->getJson('/api/public/search?per_page=1&page=2');

        $firstPageResponse->assertOk();
        $secondPageResponse->assertOk();

        $firstPageIds = collect($firstPageResponse->json('data'))->pluck('id');
        $secondPageIds = collect($secondPageResponse->json('data'))->pluck('id');

        $this->assertCount(1, $firstPageIds);
        $this->assertCount(1, $secondPageIds);
        $this->assertNotEquals($firstPageIds->first(), $secondPageIds->first());
        $this->assertEqualsCanonicalizing(
            [$firstUser->id, $secondUser->id],
            [$firstPageIds->first(), $secondPageIds->first()],
        );
    }

    /**
     * INVARIANTE DA CHAVE DE CACHE: filtro novo que não entra em
     * `ProfessionalSearchCache::buildCacheKey()` faz duas buscas diferentes colidirem, e a
     * segunda recebe o resultado da primeira. É um erro de precisão que não levanta exceção
     * e não aparece em log nenhum — só entrega o profissional errado. Este teste existe para
     * a próxima pessoa que adicionar um filtro descobrir isso no CI, não em produção.
     *
     * @return array<string, array{0: array<string, string>, 1: array<string, string>}>
     */
    public static function distinctFilterPairsProvider(): array
    {
        return [
            'especialidade' => [['specialty' => 'Cardiologia'], ['specialty' => 'Ortopedia']],
            'espécie' => [['species' => 'dog'], ['species' => 'cat']],
            'termo de busca' => [['query' => 'cardiologia'], ['query' => 'ortopedia']],
            'tipo de profissional' => [['professional_type' => 'vet'], ['professional_type' => 'petshop']],
        ];
    }

    /**
     * @param  array<string, string>  $firstFilters
     * @param  array<string, string>  $secondFilters
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('distinctFilterPairsProvider')]
    public function test_different_filters_never_share_a_cache_entry(array $firstFilters, array $secondFilters): void
    {
        $cardiologist = $this->createApprovedProfessional(
            ['name' => 'Ana Cardio'],
            ['professional_type' => 'vet', 'specialties' => ['Cardiologia'], 'species_served' => ['dog']],
        );
        $orthopedist = $this->createApprovedProfessional(
            ['name' => 'Bruno Orto'],
            ['professional_type' => 'petshop', 'specialties' => ['Ortopedia'], 'species_served' => ['cat']],
        );

        $firstIds = collect($this->getJson('/api/public/search?'.http_build_query($firstFilters))->json('data'))->pluck('id');
        $secondIds = collect($this->getJson('/api/public/search?'.http_build_query($secondFilters))->json('data'))->pluck('id');

        $this->assertTrue($firstIds->contains($cardiologist->id));
        $this->assertFalse($firstIds->contains($orthopedist->id));
        $this->assertTrue($secondIds->contains($orthopedist->id));
        $this->assertFalse($secondIds->contains($cardiologist->id));
    }
}
