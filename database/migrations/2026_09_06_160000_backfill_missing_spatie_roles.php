<?php

use App\Enums\ProfessionalType;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Contas sem NENHUM papel Spatie ficam invisíveis para todo endpoint autorizado por
 * `hasAnyRole()` — a conta loga, navega, e recebe 403 na funcionalidade que deveria ter.
 *
 * Foi exatamente essa a causa de "o veterinário busca o pet pelo CPF do tutor e não vem
 * nada": `ProfessionalSeeder` cria `vet@2pets.com`, `dra.ana@2pets.com` e
 * `dr.marcos@2pets.com` com `users.role = 'professional'` mas nunca chama `assignRole()`.
 * Logado com qualquer uma dessas contas, `GET /api/pets/search` devolvia 403 — e o app
 * mostrava "nenhum pet encontrado", escondendo o motivo real.
 *
 * O papel é derivado de `users.user_type`, que é a MESMA fonte que o cadastro
 * (`AuthController::register`) usa. Idempotente e conservadora: só toca usuário que hoje não
 * tem papel nenhum; nunca substitui nem acrescenta papel a quem já tem.
 */
return new class extends Migration
{
    public function up(): void
    {
        $roleIds = $this->roleIdsByName();

        User::query()
            ->whereDoesntHave('roles')
            ->select(['id', 'role', 'user_type'])
            ->chunkById(1000, function ($users) use ($roleIds): void {
                $this->assignRoles($users, $roleIds);
            });
    }

    /**
     * Sem `down()` funcional de propósito: não há como distinguir o papel que esta migration
     * atribuiu daquele que o usuário já tinha por outro caminho, e remover papel de conta
     * legítima é pior do que manter o backfill.
     */
    public function down(): void {}

    /**
     * @return array<string, int>
     */
    private function roleIdsByName(): array
    {
        return Role::query()->where('guard_name', 'web')->pluck('id', 'name')->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, User>  $users
     * @param  array<string, int>  $roleIds
     */
    private function assignRoles($users, array $roleIds): void
    {
        $rows = [];

        foreach ($users as $user) {
            $roleName = $this->roleNameFor($user);

            if ($roleName === null || ! isset($roleIds[$roleName])) {
                continue;
            }

            $rows[] = [
                'role_id' => $roleIds[$roleName],
                'model_type' => User::class,
                'model_id' => $user->id,
            ];
        }

        if ($rows === []) {
            return;
        }

        DB::table('model_has_roles')->insertOrIgnore($rows);
    }

    private function roleNameFor(User $user): ?string
    {
        return match ($user->role) {
            'admin' => 'admin',
            'tutor', 'company' => 'tutor',
            'professional' => ProfessionalType::tryFrom((string) $user->user_type)?->defaultRoleName(),
            default => null,
        };
    }
};
