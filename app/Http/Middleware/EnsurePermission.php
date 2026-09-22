<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate de rota por permissão Spatie — item 22 do backlog gap-simplesvet
 * (`docs/gap-simplesvet/specs/22-auditoria-log-permissoes-granulares-spec.md`).
 *
 * Uso: `->middleware('permission:financial-entries.view.own|financial-entries.view.any')`.
 * Múltiplas permissões separadas por `|` são OR — o usuário passa com pelo menos uma
 * delas, mesma semântica de `HasRoles::canAny()`.
 *
 * Este middleware só bloqueia quem não tem NENHUMA das permissões da rota. A resolução
 * fina "próprio × qualquer" (ex.: `commission.view.own` só mostra a própria comissão)
 * continua no Service/Policy do recurso — não é papel do middleware filtrar dado, só
 * gatekeeping de rota.
 *
 * Além do papel Spatie, também deixa passar quem recebeu a exceção pontual de
 * `organization_members.permissions` (`User::hasOrganizationPermissionOverride()`) —
 * revisão de segurança, achado Médio 4: até aqui essa exceção era gravada e exibida na UI,
 * mas nunca tinha efeito real aqui. Fail-closed: sem o Spatie e sem override, nega.
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            return $this->deny('Autenticação necessária.');
        }

        $required = explode('|', $permissions);

        if (! $user->canAny($required) && ! $this->hasOrganizationOverride($user, $required)) {
            return $this->deny('Você não tem permissão para acessar este recurso.');
        }

        return $next($request);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function hasOrganizationOverride(User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($user->hasOrganizationPermissionOverride($permission)) {
                return true;
            }
        }

        return false;
    }

    private function deny(string $message): JsonResponse
    {
        return response()->json(['message' => $message], Response::HTTP_FORBIDDEN);
    }
}
