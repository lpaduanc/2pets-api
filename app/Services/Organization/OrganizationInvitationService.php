<?php

namespace App\Services\Organization;

use App\Enums\OrganizationRole;
use App\Exceptions\Organization\InvitationNotAcceptableException;
use App\Mail\OrganizationInvitationMail;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Fluxo de convite: dono/admin convida por e-mail. Se o e-mail já tem conta, o aceite só
 * vincula (`accept`). Se não tem, o link leva ao cadastro (fora do escopo deste service —
 * responsabilidade do frontend) e o vínculo é criado quando essa conta nova chamar `accept`
 * autenticada.
 */
final class OrganizationInvitationService
{
    private const TOKEN_LENGTH = 40;

    public function __construct(
        private readonly VeterinarianCrmvGuard $crmvGuard,
        private readonly UserRoleReconciler $roleReconciler,
    ) {}

    /**
     * Convida ou reenvia: um convite pendente e não-expirado já existente é bloqueado antes
     * daqui (`InviteOrganizationMemberRequest`); um convite pendente EXPIRADO é reaproveitado
     * na mesma linha (nunca insere uma segunda, por causa do índice único parcial em
     * `organization_invitations_pending_unique`).
     */
    public function invite(Organization $organization, User $invitedBy, string $email, OrganizationRole $role): OrganizationInvitation
    {
        $invitation = $this->reusableExpiredInvitation($organization, $email)
            ?? new OrganizationInvitation([
                'organization_id' => $organization->id,
                'email' => $email,
            ]);

        $invitation->fill([
            'role' => $role,
            'invited_by' => $invitedBy->id,
            'token' => Str::random(self::TOKEN_LENGTH),
            'expires_at' => now()->addDays(OrganizationInvitation::DEFAULT_EXPIRATION_DAYS),
            'accepted_at' => null,
            'revoked_at' => null,
        ]);
        $invitation->save();

        Mail::to($email)->send(new OrganizationInvitationMail($organization, $invitation));

        return $invitation;
    }

    public function revoke(OrganizationInvitation $invitation): void
    {
        $invitation->update(['revoked_at' => now()]);
    }

    /**
     * Idempotente: aceitar de novo um convite já aceito devolve o vínculo existente em vez de
     * duplicar ou lançar erro — cobre o clique duplo no botão do e-mail.
     */
    public function accept(OrganizationInvitation $invitation, User $user): OrganizationMember
    {
        $this->ensureAcceptable($invitation, $user);

        if ($invitation->isAccepted()) {
            return $this->existingMembership($invitation, $user);
        }

        if ($invitation->role->isClinical()) {
            $this->crmvGuard->ensureHasCrmv($user);
        }

        return DB::transaction(function () use ($invitation, $user): OrganizationMember {
            $member = $this->createOrReactivateMembership($invitation, $user);
            $invitation->update(['accepted_at' => now()]);
            $this->roleReconciler->reconcile($user);

            return $member;
        });
    }

    private function reusableExpiredInvitation(Organization $organization, string $email): ?OrganizationInvitation
    {
        return OrganizationInvitation::query()
            ->where('organization_id', $organization->id)
            ->whereRaw('lower(email) = ?', [strtolower($email)])
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '<', now())
            ->first();
    }

    private function ensureAcceptable(OrganizationInvitation $invitation, User $user): void
    {
        abort_unless(
            strtolower($invitation->email) === strtolower($user->email),
            403,
            'Este convite foi enviado para outro e-mail.'
        );

        if ($invitation->isRevoked()) {
            throw InvitationNotAcceptableException::revoked();
        }

        if (! $invitation->isAccepted() && $invitation->isExpired()) {
            throw InvitationNotAcceptableException::expired();
        }
    }

    private function existingMembership(OrganizationInvitation $invitation, User $user): OrganizationMember
    {
        return OrganizationMember::query()
            ->where('organization_id', $invitation->organization_id)
            ->where('user_id', $user->id)
            ->firstOrFail();
    }

    private function createOrReactivateMembership(OrganizationInvitation $invitation, User $user): OrganizationMember
    {
        return OrganizationMember::updateOrCreate(
            ['organization_id' => $invitation->organization_id, 'user_id' => $user->id],
            [
                'role' => $invitation->role,
                'hire_date' => now()->toDateString(),
                'termination_date' => null,
                'is_active' => true,
            ]
        );
    }
}
