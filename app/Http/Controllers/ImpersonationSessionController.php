<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Backoffice\ImpersonationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Lado do app do tutor / 2pets Pro da impersonação aberta pelo 2pets Admin
 * (ver `ImpersonationService`). Tudo aqui, menos `exchange`, exige o token impersonado.
 */
class ImpersonationSessionController extends Controller
{
    public function __construct(private readonly ImpersonationService $impersonation) {}

    /** Troca o código de uso único da URL pelo token da sessão. Rota pública, com throttle. */
    public function exchange(Request $request): JsonResponse
    {
        $code = $request->validate(['code' => ['required', 'string', 'size:64']])['code'];
        $session = $this->impersonation->exchange($code);

        if (! $session) {
            return response()->json(['message' => 'Link de acesso expirado. Gere outro no 2pets Admin.'], 410);
        }

        $user = $session['user']->load(['roles', 'professional', 'media', 'activeOrganizationMemberships.organization']);
        $token = PersonalAccessToken::findToken($session['access_token']);

        return response()->json([
            'access_token' => $session['access_token'],
            'token_type' => 'Bearer',
            'user' => new UserResource($user),
            'profile_completed' => $user->profile_completed,
            'impersonation' => $this->impersonation->describe($token),
        ]);
    }

    /** Lista do "Trocar usuário" do banner — só do mesmo app, para a troca não mudar de origem. */
    public function users(Request $request): JsonResponse
    {
        $token = $this->impersonationToken($request);
        $term = $request->validate(['search' => ['nullable', 'string', 'max:120']])['search'] ?? null;
        $current = $request->user();

        $users = User::query()
            ->when($current->role === 'tutor',
                fn (Builder $q) => $q->where('role', 'tutor'),
                fn (Builder $q) => $q->whereIn('role', ['professional', 'company']))
            ->where('is_suspended', false)
            ->when($term, function (Builder $q, string $term) {
                $like = '%'.mb_strtolower($term).'%';
                $q->where(fn (Builder $w) => $w->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(email) LIKE ?', [$like]));
            })
            ->orderBy('name')
            ->limit(30)
            ->get(['id', 'name', 'email', 'role'])
            ->filter(fn (User $u) => ImpersonationService::isImpersonable($u));

        return response()->json([
            'data' => $users->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])->values(),
            'expires_at' => $token->expires_at?->toIso8601String(),
        ]);
    }

    public function switch(Request $request, int $id): JsonResponse
    {
        $token = $this->impersonationToken($request);
        $target = User::findOrFail($id);

        return response()->json($this->impersonation->switchTo($token, $request->user(), $target));
    }

    public function stop(Request $request): JsonResponse
    {
        $token = $this->impersonationToken($request);

        return response()->json(['redirect_url' => $this->impersonation->stop($token, $request->user())]);
    }

    private function impersonationToken(Request $request): PersonalAccessToken
    {
        $token = $request->user()->currentAccessToken();

        if (! $token instanceof PersonalAccessToken || ! $token->impersonator_id) {
            abort(404, 'Nenhuma sessão de impersonação ativa.');
        }

        return $token;
    }
}
