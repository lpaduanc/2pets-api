<?php

namespace App\Services\Backoffice;

use App\Enums\BackofficePermission;
use App\Enums\BackofficeRole;
use App\Http\Middleware\EnsureBackofficeAccess;
use App\Models\User;
use App\Notifications\Support\FrontendRoute;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * "Acessar como" do 2pets Admin — o mesmo fluxo do Hube, adaptado a três SPAs com token.
 *
 * No Hube a impersonação troca o usuário da SESSÃO porque admin e cliente vivem no mesmo SPA.
 * Aqui o admin (porta 9400), o tutor (9200) e o Pro (9300) são origens diferentes, cada uma com o
 * seu Bearer no localStorage, então:
 *
 *  1. o admin pede `start()` → nasce um token Sanctum DO USUÁRIO-ALVO, marcado com
 *     `impersonator_id` e com validade curta;
 *  2. o token não viaja na URL: vai para o cache atrás de um código de uso único (2 min), e o
 *     admin é mandado para `<app do alvo>/impersonate?code=…`;
 *  3. o app troca o código pelo token (`exchange()`) e passa a mostrar o banner
 *     "Acessando como…", alimentado por `GET /user` → `impersonation`;
 *  4. "Voltar ao admin" (`stop()`) apaga o token — a sessão impersonada morre no servidor, não
 *     só no aparelho — e devolve a URL do admin, onde o token da equipe continua intacto.
 */
class ImpersonationService
{
    public const TOKEN_NAME = 'impersonation';

    private const TOKEN_TTL_HOURS = 2;

    private const CODE_TTL_SECONDS = 120;

    /** `users.role` de quem pode ser impersonado — tutor e as contas do 2pets Pro. */
    private const IMPERSONABLE_ROLES = ['tutor', 'professional', 'company'];

    public static function isImpersonable(User $user): bool
    {
        return in_array($user->role, self::IMPERSONABLE_ROLES, true)
            && ! $user->hasAnyRole(BackofficeRole::values());
    }

    /**
     * @return array{redirect_url: string, app: string, expires_in: int}
     */
    public function start(User $staff, User $target): array
    {
        if (! self::isImpersonable($target)) {
            throw ValidationException::withMessages([
                'user' => 'Só é possível acessar como tutor ou profissional.',
            ]);
        }

        $newToken = $target->createToken(self::TOKEN_NAME, ['*'], now()->addHours(self::TOKEN_TTL_HOURS));
        $newToken->accessToken->forceFill(['impersonator_id' => $staff->id])->save();

        $code = Str::random(64);
        Cache::put($this->cacheKey($code), [
            'token' => $newToken->plainTextToken,
            'user_id' => $target->id,
        ], self::CODE_TTL_SECONDS);

        activity('backoffice')
            ->causedBy($staff)
            ->performedOn($target)
            ->event('impersonation.started')
            ->withProperties(['staff_email' => $staff->email, 'target_email' => $target->email])
            ->log('Impersonação iniciada');

        return [
            'redirect_url' => FrontendRoute::baseUrlFor($target).'/impersonate?code='.$code,
            'app' => $target->role === 'tutor' ? 'tutor' : 'pro',
            'expires_in' => self::CODE_TTL_SECONDS,
        ];
    }

    /**
     * @return array{access_token: string, user: User}|null
     */
    public function exchange(string $code): ?array
    {
        $payload = Cache::pull($this->cacheKey($code));

        if (! is_array($payload)) {
            return null;
        }

        $user = User::find($payload['user_id']);

        if (! $user) {
            return null;
        }

        return ['access_token' => $payload['token'], 'user' => $user];
    }

    /**
     * Bloco `impersonation` de `GET /user`: `null` fora de impersonação.
     *
     * @return array{impersonator: array{id: int, name: string}, expires_at: string|null}|null
     */
    public function describe(?PersonalAccessToken $token): ?array
    {
        $impersonator = $this->impersonatorOf($token);

        if (! $impersonator) {
            return null;
        }

        return [
            'impersonator' => ['id' => $impersonator->id, 'name' => $impersonator->name],
            'expires_at' => $token->expires_at?->toIso8601String(),
        ];
    }

    public function impersonatorOf(?PersonalAccessToken $token): ?User
    {
        if (! $token instanceof PersonalAccessToken || ! $token->impersonator_id) {
            return null;
        }

        return User::find($token->impersonator_id);
    }

    /** Encerra a sessão impersonada e devolve a URL de volta ao admin. */
    public function stop(PersonalAccessToken $token, User $impersonated): string
    {
        $staff = $this->impersonatorOf($token);
        $token->delete();

        if ($staff) {
            activity('backoffice')
                ->causedBy($staff)
                ->performedOn($impersonated)
                ->event('impersonation.stopped')
                ->withProperties(['staff_email' => $staff->email, 'target_email' => $impersonated->email])
                ->log('Impersonação encerrada');
        }

        return rtrim((string) config('app.frontend_admin_url'), '/').'/usuarios';
    }

    /**
     * "Trocar usuário" do banner: encerra a sessão atual e abre outra, em nome do mesmo membro da
     * equipe — que precisa continuar tendo a permissão no momento da troca.
     *
     * @return array{redirect_url: string, app: string, expires_in: int}
     */
    public function switchTo(PersonalAccessToken $token, User $impersonated, User $target): array
    {
        $staff = $this->impersonatorOf($token);

        if (! $staff || ! $this->staffCanImpersonate($staff)) {
            abort(403, 'Sua conta da equipe não pode mais acessar como usuário.');
        }

        $this->stop($token, $impersonated);

        return $this->start($staff, $target);
    }

    public function staffCanImpersonate(User $staff): bool
    {
        return ! $staff->is_suspended
            && EnsureBackofficeAccess::isStaff($staff)
            && EnsureBackofficeAccess::canAny($staff, [BackofficePermission::IMPERSONATE->value]);
    }

    private function cacheKey(string $code): string
    {
        return 'backoffice:impersonation:'.hash('sha256', $code);
    }
}
