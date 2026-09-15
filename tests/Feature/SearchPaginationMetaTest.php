<?php

namespace Tests\Feature;

use App\Models\Professional;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O `meta` de `GET /api/public/search` tem que dizer a verdade sobre até onde a paginação vai.
 *
 * A API anunciava `last_page` calculado sobre o total real enquanto o serviço materializa no
 * máximo 500 ids — ou seja, prometia 250 páginas e entregava 42. Quem paginasse até o fim (ou
 * qualquer cliente que confiasse no `meta`, que é o comportamento correto) batia num vazio
 * sem erro.
 *
 * A aritmética do teto está coberta em `Tests\Unit\ReachableLengthAwarePaginatorTest`, que
 * não precisa de 500 linhas no banco para isso. Aqui se verifica o CONTRATO DE FIO: quais
 * chaves saem, em que caminho, e se elas continuam coerentes entre si.
 */
class SearchPaginationMetaTest extends TestCase
{
    use RefreshDatabase;

    private function createProfessional(string $name): User
    {
        $user = User::factory()->professional()->create([
            'name' => $name,
            'profile_completed' => true,
            'registration_status' => 'approved',
            'is_suspended' => false,
        ]);

        Professional::factory()->create([
            'user_id' => $user->id,
            'business_name' => null,
            'description' => null,
            'specialties' => [],
            'species_served' => null,
            'professional_type' => 'vet',
        ]);

        return $user;
    }

    private function seedProfessionals(int $count): void
    {
        for ($index = 1; $index <= $count; $index++) {
            $this->createProfessional('Profissional '.$index);
        }
    }

    public function test_the_offset_path_publishes_how_far_it_can_go(): void
    {
        $this->seedProfessionals(5);

        $response = $this->getJson('/api/public/search?per_page=2');
        $response->assertOk();

        $response->assertJsonStructure(['meta' => ['total', 'total_reachable', 'truncated', 'last_page']]);
    }

    public function test_a_result_set_below_the_ceiling_is_reported_as_not_truncated(): void
    {
        $this->seedProfessionals(5);

        $meta = $this->getJson('/api/public/search?per_page=2')->assertOk()->json('meta');

        $this->assertFalse($meta['truncated']);
        $this->assertSame($meta['total'], $meta['total_reachable']);
        $this->assertSame(3, $meta['last_page']);
    }

    /**
     * `last_page` e `links.last` precisam concordar: uma URL que aponta para página vazia é a
     * mesma mentira do `last_page` errado, só escrita em outro campo.
     */
    public function test_the_last_link_agrees_with_the_last_page(): void
    {
        $this->seedProfessionals(5);

        $payload = $this->getJson('/api/public/search?per_page=2')->assertOk()->json();

        $this->assertStringContainsString('page='.$payload['meta']['last_page'], $payload['links']['last']);
    }

    /**
     * A última página anunciada tem que ter conteúdo, e a seguinte não pode existir — é
     * exatamente o que falhava antes.
     */
    public function test_the_announced_last_page_has_content(): void
    {
        $this->seedProfessionals(5);

        $lastPage = $this->getJson('/api/public/search?per_page=2')->assertOk()->json('meta.last_page');

        $response = $this->getJson('/api/public/search?per_page=2&page='.$lastPage);

        $this->assertNotEmpty($response->assertOk()->json('data'));
        $this->assertNull($response->json('links.next'));
    }

    /**
     * O caminho de cursor não passa pelo cache de ids e portanto não tem teto: anunciar um
     * ali seria mentir na outra direção.
     */
    public function test_the_cursor_path_announces_no_ceiling(): void
    {
        $this->seedProfessionals(5);

        $meta = $this->getJson('/api/public/search?per_page=2&cursor=')->assertOk()->json('meta');

        $this->assertArrayNotHasKey('total_reachable', $meta);
        $this->assertArrayNotHasKey('truncated', $meta);
    }

    public function test_the_cursor_path_still_publishes_the_suggestion_list(): void
    {
        $this->seedProfessionals(5);

        $meta = $this->getJson('/api/public/search?per_page=2&cursor=')->assertOk()->json('meta');

        $this->assertArrayHasKey('suggestions', $meta);
    }
}
