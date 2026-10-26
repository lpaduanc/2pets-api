<?php

namespace Tests\Feature;

use App\Enums\BackofficePermission;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * 2pets Admin: login da equipe, permissões diretas, cadastro assistido, equipe e o ciclo
 * completo de "Acessar como" (start → exchange → /user → switch → stop).
 */
class BackofficeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function staff(string $role, array $permissions = [], array $attributes = []): User
    {
        $user = User::factory()->create(['role' => 'staff', 'user_type' => null, 'password' => bcrypt('Senha12345')] + $attributes);
        $user->assignRole($role);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function bearer(User $user, string $name = 'backoffice'): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken($name)->plainTextToken];
    }

    public function test_only_staff_can_log_in_to_the_backoffice(): void
    {
        $tutor = User::factory()->tutor()->create(['password' => bcrypt('Senha12345')]);
        $support = $this->staff('support', [BackofficePermission::USERS_VIEW->value]);

        $this->postJson('/api/backoffice/auth/login', ['email' => $tutor->email, 'password' => 'Senha12345'])
            ->assertStatus(401);

        $this->postJson('/api/backoffice/auth/login', ['email' => $support->email, 'password' => 'Senha12345'])
            ->assertOk()
            ->assertJsonPath('user.role', 'support')
            ->assertJsonPath('user.permissions', [BackofficePermission::USERS_VIEW->value]);
    }

    public function test_permissions_are_enforced_per_route(): void
    {
        $support = $this->staff('support', [BackofficePermission::USERS_VIEW->value]);
        $headers = $this->bearer($support);

        $this->getJson('/api/backoffice/users', $headers)->assertOk();
        $this->postJson('/api/backoffice/users', [], $headers)->assertForbidden();
        $this->getJson('/api/backoffice/team', $headers)->assertForbidden();
    }

    public function test_platform_user_list_never_includes_staff(): void
    {
        $super = $this->staff('super_admin');
        $tutor = User::factory()->tutor()->create();

        $ids = collect($this->getJson('/api/backoffice/users', $this->bearer($super))->assertOk()->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($tutor->id));
        $this->assertFalse($ids->contains($super->id));
    }

    public function test_staff_can_register_a_professional_with_generated_password(): void
    {
        $support = $this->staff('support', [BackofficePermission::USERS_CREATE->value]);

        $response = $this->postJson('/api/backoffice/users', [
            'name' => 'Clínica Teste',
            'email' => 'clinica@example.com',
            'phone' => '11999999999',
            'user_type' => 'clinic',
        ], $this->bearer($support))->assertCreated();

        $this->assertNotEmpty($response->json('generated_password'));

        $user = User::where('email', 'clinica@example.com')->firstOrFail();
        $this->assertSame('professional', $user->role);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($user->hasRole('clinic_owner'));
    }

    public function test_full_impersonation_cycle(): void
    {
        $support = $this->staff('support', [BackofficePermission::IMPERSONATE->value]);
        $tutor = User::factory()->tutor()->create();
        $otherTutor = User::factory()->tutor()->create();

        $start = $this->postJson("/api/backoffice/users/{$tutor->id}/impersonate", [], $this->bearer($support))
            ->assertOk()
            ->assertJsonPath('app', 'tutor');

        parse_str(parse_url($start->json('redirect_url'), PHP_URL_QUERY), $query);

        $exchange = $this->postJson('/api/auth/impersonation/exchange', ['code' => $query['code']])
            ->assertOk()
            ->assertJsonPath('user.id', $tutor->id)
            ->assertJsonPath('impersonation.impersonator.id', $support->id);

        // Uso único.
        $this->postJson('/api/auth/impersonation/exchange', ['code' => $query['code']])->assertStatus(410);

        $headers = ['Authorization' => 'Bearer '.$exchange->json('access_token')];
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/user', $headers)
            ->assertOk()
            ->assertJsonPath('data.id', $tutor->id)
            ->assertJsonPath('impersonation.impersonator.name', $support->name);

        // Token impersonado nunca abre o backoffice.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/backoffice/auth/me', $headers)->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->postJson("/api/auth/impersonation/switch/{$otherTutor->id}", [], $headers)
            ->assertOk()
            ->assertJsonStructure(['redirect_url']);

        $this->assertSame(0, PersonalAccessToken::where('tokenable_id', $tutor->id)->count());
        $this->assertSame(1, PersonalAccessToken::where('tokenable_id', $otherTutor->id)->whereNotNull('impersonator_id')->count());
    }

    public function test_stop_revokes_the_impersonated_token(): void
    {
        $super = $this->staff('super_admin');
        $tutor = User::factory()->tutor()->create();

        $start = $this->postJson("/api/backoffice/users/{$tutor->id}/impersonate", [], $this->bearer($super))->assertOk();
        parse_str(parse_url($start->json('redirect_url'), PHP_URL_QUERY), $query);
        $token = $this->postJson('/api/auth/impersonation/exchange', ['code' => $query['code']])->json('access_token');

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/impersonation/stop', [], ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('redirect_url', rtrim(config('app.frontend_admin_url'), '/').'/usuarios');

        $this->assertNull(PersonalAccessToken::findToken($token));
    }

    public function test_staff_cannot_be_impersonated(): void
    {
        $super = $this->staff('super_admin');
        $support = $this->staff('support');

        // Fora da lista de usuários da plataforma → 404.
        $this->postJson("/api/backoffice/users/{$support->id}/impersonate", [], $this->bearer($super))->assertNotFound();
    }

    public function test_team_management_keeps_one_super_admin_and_blocks_self_lockout(): void
    {
        $super = $this->staff('super_admin');
        $headers = $this->bearer($super);

        $this->putJson("/api/backoffice/team/{$super->id}", ['role' => 'support'], $headers)->assertStatus(422);
        $this->putJson("/api/backoffice/team/{$super->id}", ['is_active' => false], $headers)->assertStatus(422);
        $this->deleteJson("/api/backoffice/team/{$super->id}", [], $headers)->assertStatus(422);

        $created = $this->postJson('/api/backoffice/team', [
            'name' => 'Pessoa do Suporte',
            'email' => 'suporte@2pets.com.br',
            'password' => 'Suporte12345',
            'role' => 'support',
            'permissions' => [BackofficePermission::USERS_VIEW->value],
        ], $headers)->assertCreated()->assertJsonPath('data.permissions', [BackofficePermission::USERS_VIEW->value]);

        $id = $created->json('data.id');

        $this->putJson("/api/backoffice/team/{$id}", [
            'permissions' => [BackofficePermission::USERS_VIEW->value, BackofficePermission::IMPERSONATE->value],
        ], $headers)->assertOk()->assertJsonPath('data.permissions', [
            BackofficePermission::USERS_VIEW->value,
            BackofficePermission::IMPERSONATE->value,
        ]);

        $this->putJson("/api/backoffice/team/{$id}", ['is_active' => false], $headers)
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->postJson('/api/backoffice/auth/login', ['email' => 'suporte@2pets.com.br', 'password' => 'Suporte12345'])
            ->assertForbidden();
    }
}
