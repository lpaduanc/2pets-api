<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * `App\Http\Middleware\EnsurePermission` (alias `permission`) — item 22 do backlog
 * gap-simplesvet. Rota de teste isolada, sem tocar `routes/api.php`, para exercitar só o
 * middleware (gate de rota), não um endpoint de produção.
 */
class EnsurePermissionMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        Route::middleware(['api', 'auth:sanctum', 'permission:financial-entries.view.own|financial-entries.view.any'])
            ->get('/_test/permission-gate', fn () => response()->json(['ok' => true]));
    }

    public function test_user_without_any_of_the_permissions_gets_403(): void
    {
        $tutor = User::factory()->tutor()->create();

        $this->actingAs($tutor)
            ->getJson('/_test/permission-gate')
            ->assertForbidden()
            ->assertJsonPath('message', 'Você não tem permissão para acessar este recurso.');
    }

    public function test_user_with_one_of_the_or_permissions_passes(): void
    {
        $vetFreelancer = User::factory()->professional()->create();

        $this->actingAs($vetFreelancer)
            ->getJson('/_test/permission-gate')
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_unauthenticated_request_is_denied_before_permission_check(): void
    {
        $this->getJson('/_test/permission-gate')->assertUnauthorized();
    }
}
