<?php

namespace App\Notifications\Support;

use App\Models\User;

/**
 * Centralized frontend deep-link paths used by notification `action_url` (in-app) and
 * `toMail()->action()` (e-mail) targets.
 *
 * Every path here was previously a string literal duplicated across notification classes —
 * that's how BUG 1 (2026-09-13) happened: `PetVetAccessRequested` hardcoded a stale
 * `/app/vets/solicitacoes` in two places while every sibling notification already used
 * `/tutor/...` or `/professional/...`. One typo, one 404, no compiler to catch it.
 */
final class FrontendRoute
{
    private const TUTOR_ROLE = 'tutor';

    /** Path prefixes served by the professional app (port 9300); everything else is the tutor app. */
    private const PROFESSIONAL_APP_PREFIXES = ['/professional', '/company', '/admin', '/accept-invitation', '/documento'];

    public const TUTOR_DASHBOARD = '/tutor/dashboard';

    public const TUTOR_HEALTH = '/tutor/health';

    public const TUTOR_APPOINTMENTS = '/tutor/appointments';

    public const TUTOR_WALLET = '/tutor/wallet';

    public const TUTOR_SUBSCRIPTION = '/tutor/subscription';

    public const TUTOR_PETS_ADD = '/tutor/pets/add';

    public const TUTOR_VET_ACCESS_REQUESTS = '/tutor/vets/solicitacoes';

    public const VERIFY_EMAIL = '/verify-email';

    public const RESET_PASSWORD = '/reset-password';

    public const PROFESSIONAL_DASHBOARD = '/professional/dashboard';

    public const PROFESSIONAL_APPOINTMENTS = '/professional/appointments';

    public const PROFESSIONAL_PROFILE = '/professional/profile';

    public const PROFESSIONAL_MY_PATIENTS = '/professional/meus-pacientes';

    public const TUTOR_LOST_PETS = '/tutor/pets-perdidos';

    public const PROFESSIONAL_LOST_PETS = '/professional/pets-perdidos';

    public const COMPANY_LOST_PETS = '/company/pets-perdidos';

    /**
     * O menu "Pets Perdidos" existe nos tres layouts autenticados, com prefixo
     * diferente em cada um. O alerta de raio notifica tutor, profissional e
     * clinica na mesma varredura, entao o destino tem que ser escolhido por
     * destinatario — um `action_url` fixo levaria dois tercos das pessoas a uma
     * rota que o guard do router recusa.
     *
     * O corte e `users.role`, o mesmo que os getters `isTutor`/`isCompany` do
     * `auth-store` usam para decidir o layout. Qualquer outro papel (vet
     * freelancer, staff de clinica) cai no layout profissional, que e onde o
     * router ja os coloca.
     */
    public static function lostPetsFor(User $user): string
    {
        return match ($user->role) {
            'tutor' => self::TUTOR_LOST_PETS,
            'company' => self::COMPANY_LOST_PETS,
            default => self::PROFESSIONAL_LOST_PETS,
        };
    }

    public static function tutorQuote(int $quoteId): string
    {
        return "/tutor/orcamentos/{$quoteId}";
    }

    public static function professionalQuote(int $quoteId): string
    {
        return "/professional/orcamentos/{$quoteId}";
    }

    /** Link de aprovação sem login (doc 24) — o token vai em texto puro só aqui. */
    public static function publicQuoteDecision(string $plainToken): string
    {
        return "/orcamento/{$plainToken}";
    }

    public static function tutorPetAuthorizedVets(int $petId): string
    {
        return "/tutor/pets/{$petId}/authorized-vets";
    }

    /** Aba "Resumo" do perfil do pet — destino padrão de alterações no cadastro do pet. */
    public static function tutorPetOverview(int $petId): string
    {
        return "/tutor/pets/{$petId}/overview";
    }

    /** Aba "Saúde" do perfil do pet — destino de vacina/vermífugo/medicação/cirurgia/exame/internação. */
    public static function tutorPetHealth(int $petId): string
    {
        return "/tutor/pets/{$petId}/health";
    }

    /**
     * Builds an absolute link to the frontend app for e-mail actions.
     *
     * A bare `url()` call inside a Notification resolves against `APP_URL` (the Laravel API
     * host, e.g. http://localhost:8000) — not the Quasar frontend (port 9200). Every deep link
     * opened from an e-mail client must go through here instead.
     */
    public static function absolute(string $path): string
    {
        return self::baseUrlForPath($path).$path;
    }

    /**
     * Absolute link for a path whose app depends on who receives it (e.g. e-mail
     * verification, password reset), not on the path itself.
     */
    public static function absoluteFor(User $user, string $path): string
    {
        return self::baseUrlFor($user).$path;
    }

    /** Tutor app for `users.role = tutor`; professional app for every other role. */
    public static function baseUrlFor(User $user): string
    {
        return $user->role === self::TUTOR_ROLE ? self::tutorBaseUrl() : self::professionalBaseUrl();
    }

    public static function tutorBaseUrl(): string
    {
        return rtrim((string) config('app.frontend_tutor_url'), '/');
    }

    public static function professionalBaseUrl(): string
    {
        return rtrim((string) config('app.frontend_pro_url'), '/');
    }

    private static function baseUrlForPath(string $path): string
    {
        foreach (self::PROFESSIONAL_APP_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/') || str_starts_with($path, $prefix.'?')) {
                return self::professionalBaseUrl();
            }
        }

        return self::tutorBaseUrl();
    }
}
