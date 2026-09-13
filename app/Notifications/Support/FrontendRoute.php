<?php

namespace App\Notifications\Support;

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
    public const TUTOR_DASHBOARD = '/tutor/dashboard';

    public const TUTOR_HEALTH = '/tutor/health';

    public const TUTOR_APPOINTMENTS = '/tutor/appointments';

    public const TUTOR_WALLET = '/tutor/wallet';

    public const TUTOR_SUBSCRIPTION = '/tutor/subscription';

    public const TUTOR_PETS_ADD = '/tutor/pets/add';

    public const TUTOR_VET_ACCESS_REQUESTS = '/tutor/vets/solicitacoes';

    public const PROFESSIONAL_DASHBOARD = '/professional/dashboard';

    public const PROFESSIONAL_PROFILE = '/professional/profile';

    public const PROFESSIONAL_MY_PATIENTS = '/professional/meus-pacientes';

    public static function tutorPetAuthorizedVets(int $petId): string
    {
        return "/tutor/pets/{$petId}/authorized-vets";
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
        return rtrim((string) config('app.frontend_url'), '/').$path;
    }
}
