<?php

namespace App\Console\Commands;

use App\Enums\BackofficePermission;
use App\Enums\BackofficeRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Primeiro acesso ao 2pets Admin: a tela de equipe só existe para quem já é super admin, então o
 * primeiro nasce por aqui. Rodar de novo com o mesmo e-mail promove a conta (e pode trocar a senha).
 *
 *   php artisan backoffice:super-admin voce@2pets.com.br --name="Seu Nome"
 */
class CreateBackofficeSuperAdmin extends Command
{
    protected $signature = 'backoffice:super-admin {email} {--name=} {--password= : Sem esta opção, uma senha é gerada e exibida}';

    protected $description = 'Cria ou promove um super admin do 2pets Admin';

    public function handle(): int
    {
        foreach (BackofficePermission::values() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        foreach (BackofficeRole::values() as $role) {
            Role::findOrCreate($role, 'web');
        }

        $email = mb_strtolower((string) $this->argument('email'));
        $user = User::where('email', $email)->first();
        $password = $this->option('password') ?: ($user ? null : Str::password(16, symbols: false));

        if ($user && in_array($user->role, ['tutor', 'professional', 'company'], true)) {
            $this->error("{$email} é uma conta de tutor/profissional. A equipe precisa de uma conta própria.");

            return self::FAILURE;
        }

        $user ??= new User(['email' => $email, 'email_verified_at' => now(), 'registration_status' => 'approved']);
        $user->fill([
            'name' => $this->option('name') ?: ($user->name ?: 'Super admin'),
            'role' => BackofficeRole::USERS_ROLE_COLUMN,
            'user_type' => null, // CHECK de `users.user_type` só aceita tipos de cadastro
            'profile_completed' => true,
        ]);
        $user->is_suspended = false;
        if ($password) {
            $user->password = bcrypt($password);
        }
        $user->save();

        $otherRoles = $user->getRoleNames()->diff(BackofficeRole::values())->all();
        $user->syncRoles([...$otherRoles, BackofficeRole::SUPER_ADMIN->value]);
        $user->givePermissionTo(BackofficePermission::values());

        $this->info("Super admin pronto: {$user->email}");
        if ($password && ! $this->option('password')) {
            $this->line("Senha gerada: {$password}");
        }

        return self::SUCCESS;
    }
}
