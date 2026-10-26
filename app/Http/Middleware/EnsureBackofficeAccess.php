<?php

namespace App\Http\Middleware;

use App\Enums\BackofficePermission;
use App\Enums\BackofficeRole;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Portão das rotas `/backoffice/*` (2pets Admin).
 *
 * Uso: `->middleware('backoffice')` só exige ser da equipe; `->middleware('backoffice:perm-a|perm-b')`
 * exige também uma das permissões (OR).
 *
 * Diferente do `EnsurePermission`, NÃO aceita a exceção de `organization_members.permissions`:
 * aquele JSON é editado pelo dono de uma clínica, e nenhum dono de clínica pode se dar acesso ao
 * backoffice. Também só vale permissão DIRETA — o papel não concede nada aqui, para que desmarcar
 * uma permissão na tela de equipe tenha efeito de verdade (ver `BackofficeRole`).
 */
class EnsureBackofficeAccess
{
    public function handle(Request $request, Closure $next, ?string $permissions = null): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if (! $user) {
            return $this->deny('Autenticação necessária.', 401);
        }

        // Token de impersonação é do tutor/profissional, nunca da equipe — mesmo que o
        // impersonado tivesse papel de equipe (o serviço já recusa), o backoffice não abre.
        if ($user->currentAccessToken()?->impersonator_id ?? null) {
            return $this->deny('Sessão de impersonação não acessa o admin.');
        }

        if (! self::isStaff($user) || $user->is_suspended) {
            return $this->deny('Acesso restrito à equipe do 2pets.');
        }

        if ($permissions !== null && ! self::canAny($user, explode('|', $permissions))) {
            return $this->deny('Você não tem permissão para esta ação.');
        }

        return $next($request);
    }

    public static function isStaff(User $user): bool
    {
        return $user->hasAnyRole(BackofficeRole::values());
    }

    /** @param  list<string>  $permissions */
    public static function canAny(User $user, array $permissions): bool
    {
        if ($user->hasRole(BackofficeRole::SUPER_ADMIN->value)) {
            return true;
        }

        foreach ($permissions as $permission) {
            if ($user->hasDirectPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public static function effectivePermissions(User $user): array
    {
        if ($user->hasRole(BackofficeRole::SUPER_ADMIN->value)) {
            return BackofficePermission::values();
        }

        return array_values(array_intersect(
            BackofficePermission::values(),
            $user->getDirectPermissions()->pluck('name')->all(),
        ));
    }

    private function deny(string $message, int $status = 403): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }
}
