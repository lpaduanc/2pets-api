<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `GET /register/professional-schema` responde no idioma pedido em `Accept-Language`
 * (pt-BR padrão/fallback, en-US suportado) — ver `App\Enums\AppLocale` e
 * `App\Http\Middleware\SetLocaleFromAcceptLanguage`. O teste que mais importa aqui é o de
 * vazamento de cache: `ProfessionalSchemaBuilder::cacheKey()` precisa incluir o locale, ou
 * o primeiro idioma pedido em produção "congela" o payload pra todo mundo
 * (`Cache::rememberForever` nunca expira sozinho).
 */
class ProfessionalSchemaLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['maps.googleapis.com/*' => Http::response(['status' => 'ZERO_RESULTS', 'results' => []])]);
        Mail::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_schema_defaults_to_portuguese_without_accept_language_header(): void
    {
        $response = $this->requestSchema(acceptLanguage: '');

        $response->assertOk()
            ->assertJsonPath('labels.professional_types.vet', 'Veterinário Volante')
            ->assertJsonPath('labels.species.dog', 'Cão')
            ->assertJsonPath('service_items.0.label', 'Vermifugação');
    }

    public function test_schema_responds_in_english_when_requested(): void
    {
        $response = $this->requestSchema('en-US');

        $response->assertOk()
            ->assertJsonPath('labels.professional_types.vet', 'Mobile Veterinarian')
            ->assertJsonPath('labels.species.dog', 'Dog')
            ->assertJsonPath('service_items.0.label', 'Deworming');
    }

    public function test_unknown_locale_falls_back_to_portuguese(): void
    {
        $response = $this->requestSchema('es-ES');

        $response->assertOk()->assertJsonPath('labels.professional_types.vet', 'Veterinário Volante');
    }

    /**
     * A regra que mais importa: duas requisições na mesma execução, uma em cada idioma, não
     * podem compartilhar a mesma entrada de cache — se compartilhassem, a primeira chamada
     * "congelaria" o payload no idioma dela para a segunda.
     */
    public function test_cache_does_not_leak_one_locale_into_the_other(): void
    {
        $portuguese = $this->requestSchema(acceptLanguage: '');
        $english = $this->requestSchema('en-US');
        $portugueseAgain = $this->requestSchema(acceptLanguage: '');

        $portuguese->assertJsonPath('labels.professional_types.vet', 'Veterinário Volante');
        $english->assertJsonPath('labels.professional_types.vet', 'Mobile Veterinarian');
        $portugueseAgain->assertJsonPath('labels.professional_types.vet', 'Veterinário Volante');
    }

    /**
     * O harness de teste (`Symfony\Component\HttpFoundation\Request::create()`) injeta
     * `HTTP_ACCEPT_LANGUAGE = 'en-us,en;q=0.5'` por padrão quando o header não é passado —
     * um comportamento só do cliente de teste, nenhum servidor real faz isso. Por isso os
     * testes desta classe sempre mandam o header explicitamente, inclusive vazio (`''`)
     * para simular a ausência real dele numa requisição de produção.
     */
    private function requestSchema(string $acceptLanguage): TestResponse
    {
        return $this->getJson('/api/register/professional-schema', ['Accept-Language' => $acceptLanguage]);
    }
}
