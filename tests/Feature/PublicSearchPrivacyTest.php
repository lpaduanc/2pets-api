<?php

namespace Tests\Feature;

use App\Models\Favorite;
use App\Models\Professional;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression test for the public search LGPD leak: `GET /api/public/search` (and
 * `/nearby`, `/featured`) is unauthenticated + throttled 30/min, and used to return
 * `email`/`phone` for every professional — the whole base was scrapeable without an
 * account, in direct conflict with the CLAUDE.md card spec ("foto, nome, tipo,
 * especialidades, distância, avaliação média" + login-gated profile).
 *
 * The authenticated path (`GET /favorites`, still `ProfessionalSearchResource`) is
 * intentionally NOT touched by the fix and keeps returning full contact — covered here
 * too so a future change doesn't silently strip it from the logged-in experience.
 */
class PublicSearchPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private const RAW_LATITUDE = -23.55052000;

    private const RAW_LONGITUDE = -46.63330900;

    private function createApprovedProfessional(array $overrides = [], string $professionalType = 'clinic'): User
    {
        $user = User::factory()->professional()->create(array_merge([
            'profile_completed' => true,
            'registration_status' => 'approved',
            'is_suspended' => false,
            'email' => 'clinica.vidaanimal@2pets.com.br',
            'phone' => '1133445566',
            'latitude' => self::RAW_LATITUDE,
            'longitude' => self::RAW_LONGITUDE,
        ], $overrides));

        Professional::factory()->create(['user_id' => $user->id, 'professional_type' => $professionalType]);

        return $user;
    }

    public function test_public_search_never_exposes_email_or_phone(): void
    {
        $this->createApprovedProfessional();

        $response = $this->getJson('/api/public/search')->assertOk();

        $response->assertJsonMissingPath('data.0.email')
            ->assertJsonMissingPath('data.0.phone');

        $this->assertStringNotContainsString('clinica.vidaanimal@2pets.com.br', $response->getContent());
        $this->assertStringNotContainsString('1133445566', $response->getContent());
    }

    public function test_public_nearby_never_exposes_email_or_phone(): void
    {
        $this->createApprovedProfessional();

        $response = $this->getJson('/api/public/nearby?'.http_build_query([
            'latitude' => self::RAW_LATITUDE,
            'longitude' => self::RAW_LONGITUDE,
            'radius_km' => 50,
        ]))->assertOk();

        $response->assertJsonMissingPath('data.0.email')
            ->assertJsonMissingPath('data.0.phone');
    }

    public function test_public_featured_never_exposes_email_or_phone(): void
    {
        $this->createApprovedProfessional();
        Professional::query()->update(['is_featured' => true]);

        $response = $this->getJson('/api/public/featured')->assertOk();

        $response->assertJsonMissingPath('data.0.email')
            ->assertJsonMissingPath('data.0.phone');
    }

    /**
     * Mobile professionals (vet volante) often work from home: coordinates drop to 2 decimals
     * (~1.1 km), the street is withheld and the distance comes in 500 m steps — exact meters
     * from three search points would trilaterate the address the rounding tried to hide.
     */
    public function test_public_search_blurs_the_location_of_a_mobile_professional(): void
    {
        $this->createApprovedProfessional(['address' => 'Rua da Casa do Vet', 'number' => '77'], 'vet');

        $data = $this->getJson('/api/public/search?'.http_build_query([
            'latitude' => self::RAW_LATITUDE + 0.004,
            'longitude' => self::RAW_LONGITUDE,
        ]))->assertOk()->json('data.0');

        $this->assertSame(round(self::RAW_LATITUDE, 2), $data['latitude']);
        $this->assertSame(round(self::RAW_LONGITUDE, 2), $data['longitude']);
        $this->assertNull($data['address']);
        $this->assertSame(0, $data['distance_m'] % 500);
    }

    /**
     * Distância continua resolvida sem a coordenada exata: a precisão cai para ~110m
     * (3 casas decimais), o suficiente para plotar o pin de "perto de você" no mapa da
     * busca sem devolver o ponto exato do profissional (ponto fixo — clínica).
     */
    public function test_public_search_rounds_coordinates_instead_of_removing_them(): void
    {
        $this->createApprovedProfessional();

        $response = $this->getJson('/api/public/search?'.http_build_query([
            'latitude' => self::RAW_LATITUDE,
            'longitude' => self::RAW_LONGITUDE,
        ]))->assertOk();

        $data = $response->json('data.0');

        $this->assertNotNull($data['latitude']);
        $this->assertNotNull($data['longitude']);
        $this->assertSame(round(self::RAW_LATITUDE, 3), $data['latitude']);
        $this->assertSame(round(self::RAW_LONGITUDE, 3), $data['longitude']);
        $this->assertNotEquals(self::RAW_LATITUDE, $data['latitude']);
    }

    /**
     * Regressão: `$this->distance_km ? round(...) : null` tratava distância 0 (profissional
     * bem na coordenada buscada) como "não deu para calcular". `0.0` real precisa continuar
     * `0`, distinto de `null` (busca sem lat/lng, `NULL::double precision` no SQL).
     */
    public function test_public_search_returns_zero_distance_for_professional_at_the_exact_coordinate(): void
    {
        $this->createApprovedProfessional();

        $response = $this->getJson('/api/public/search?'.http_build_query([
            'latitude' => self::RAW_LATITUDE,
            'longitude' => self::RAW_LONGITUDE,
        ]))->assertOk();

        $response->assertJsonPath('data.0.distance_km', 0);
    }

    public function test_public_search_returns_null_distance_without_coordinates(): void
    {
        $this->createApprovedProfessional();

        $response = $this->getJson('/api/public/search')->assertOk();

        $response->assertJsonPath('data.0.distance_km', null);
    }

    /**
     * Mesmo problema de zero falsy em `starting_price`: serviço gratuito (captação, primeira
     * avaliação) virava "sem preço informado" em vez de "grátis".
     */
    public function test_public_search_returns_zero_starting_price_for_a_free_service(): void
    {
        $professional = $this->createApprovedProfessional();

        Service::create([
            'professional_id' => $professional->id,
            'name' => 'Primeira avaliação',
            'category' => 'consultation',
            'duration' => 30,
            'price' => 0,
            'active' => true,
        ]);

        $response = $this->getJson('/api/public/search')->assertOk();

        $response->assertJsonPath('data.0.starting_price', 0);
    }

    public function test_authenticated_favorites_still_expose_full_contact(): void
    {
        $professional = $this->createApprovedProfessional();
        $tutor = User::factory()->tutor()->create();

        Favorite::create(['user_id' => $tutor->id, 'professional_id' => $professional->id]);

        Sanctum::actingAs($tutor);

        $response = $this->getJson('/api/favorites')->assertOk();

        $response->assertJsonPath('data.0.professional.email', 'clinica.vidaanimal@2pets.com.br')
            ->assertJsonPath('data.0.professional.phone', '1133445566');
    }

    /**
     * Regressão de BUG 3 (2026-09-13): a busca pública foi corrigida com
     * `PublicProfessionalSearchResource`, mas `GET /public/professionals/{id}` continuava
     * usando `ProfessionalSearchResource` sem filtro nenhum — o e-mail/telefone de qualquer
     * profissional saía sem autenticação, bastando iterar IDs sequenciais.
     */
    public function test_public_professional_show_never_exposes_contact_when_anonymous(): void
    {
        $professional = $this->createApprovedProfessional();

        $response = $this->getJson("/api/public/professionals/{$professional->id}")->assertOk();

        $response->assertJsonMissingPath('data.email')
            ->assertJsonMissingPath('data.phone');

        $this->assertStringNotContainsString('clinica.vidaanimal@2pets.com.br', $response->getContent());
        $this->assertStringNotContainsString('1133445566', $response->getContent());
    }

    /**
     * O perfil continua público por desenho (link compartilhável, SEO, o gancho de conversão
     * do CLAUDE.md "veja o perfil completo de Dr. João") — o contato é o que fica login-gated,
     * exatamente como o card de busca e como o `isAuthenticated` já usado pelo próprio
     * `ProfessionalProfile.vue` para agendar/mandar mensagem.
     */
    public function test_public_professional_show_exposes_contact_when_authenticated(): void
    {
        $professional = $this->createApprovedProfessional();
        $tutor = User::factory()->tutor()->create();

        Sanctum::actingAs($tutor);

        $response = $this->getJson("/api/public/professionals/{$professional->id}")->assertOk();

        $response->assertJsonPath('data.email', 'clinica.vidaanimal@2pets.com.br')
            ->assertJsonPath('data.phone', '1133445566');
    }
}
