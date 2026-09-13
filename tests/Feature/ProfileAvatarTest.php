<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Foto de perfil (`POST`/`DELETE /api/profile/avatar`).
 *
 * Antes destes endpoints a tela "Meu Perfil" tinha o botao de camera, mas ele
 * so abria um toast de "em desenvolvimento" — nao havia rota nenhuma no
 * backend, e `UserProfileResource` sequer devolvia `avatar_url`.
 */
class ProfileAvatarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Disco fake: o teste nunca escreve em storage/app/public de verdade.
        Storage::fake('public');
    }

    public function test_profile_payload_exposes_avatar_url(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('avatar_url', null);

        $this->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('foto.png', 300, 300),
        ])->assertOk();

        $response = $this->getJson('/api/profile')->assertOk();

        $this->assertNotNull($response->json('avatar_url'));
    }

    public function test_upload_stores_avatar_and_returns_the_full_profile(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('foto.png', 300, 300),
        ]);

        $response->assertOk()
            // Mesmo shape do GET/PUT: o formulario reatribui a resposta ao estado.
            ->assertJsonStructure(['id', 'name', 'email', 'avatar_url', 'address']);

        $this->assertNotNull($response->json('avatar_url'));
        $this->assertCount(1, $user->refresh()->getMedia('avatar'));
    }

    /**
     * A colecao `avatar` e `singleFile()`: a segunda foto substitui a primeira
     * em vez de acumular. Se isso regredir, cada troca de foto deixa lixo no
     * disco e `getFirstMediaUrl()` passa a devolver a foto ERRADA (a mais antiga).
     */
    public function test_second_upload_replaces_the_previous_avatar(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('primeira.png', 200, 200),
        ])->assertOk();

        $second = $this->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('segunda.png', 200, 200),
        ])->assertOk();

        $media = $user->refresh()->getMedia('avatar');

        $this->assertCount(1, $media);
        $this->assertSame('segunda.png', $media->first()->file_name);
        $this->assertStringContainsString('segunda.png', (string) $second->json('avatar_url'));
    }

    public function test_delete_removes_the_avatar(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('foto.png', 200, 200),
        ])->assertOk();

        $this->deleteJson('/api/profile/avatar')
            ->assertOk()
            ->assertJsonPath('avatar_url', null);

        $this->assertCount(0, $user->refresh()->getMedia('avatar'));
    }

    /**
     * Extensao de imagem sobre conteudo que nao e imagem — o vetor classico de
     * upload malicioso. A regra `image` resolve o tipo pelo conteudo, nao pelo
     * nome do arquivo.
     */
    public function test_non_image_disguised_as_png_is_rejected(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->createWithContent('payload.png', '<?php echo 1; ?>'),
        ])->assertStatus(422)->assertJsonValidationErrors('avatar');

        $this->assertCount(0, $user->refresh()->getMedia('avatar'));
    }

    /**
     * `->size()` reporta o tamanho sem materializar 6 MB de bytes. O GD do
     * container nao tem suporte a JPEG (`imagejpeg` indefinido), entao toda
     * imagem fake deste arquivo e PNG — trocar a extensao aqui quebra a suite
     * inteira com LogicException, nao com falha de asserção.
     */
    public function test_oversized_image_is_rejected(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // 5 MB e o teto; 6 MB precisa reprovar.
        $this->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('grande.png')->size(6144),
        ])->assertStatus(422)->assertJsonValidationErrors('avatar');
    }

    public function test_missing_file_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/profile/avatar')
            ->assertStatus(422)
            ->assertJsonValidationErrors('avatar');
    }

    public function test_guest_cannot_upload_or_delete(): void
    {
        $this->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('foto.png'),
        ])->assertUnauthorized();

        $this->deleteJson('/api/profile/avatar')->assertUnauthorized();
    }
}
