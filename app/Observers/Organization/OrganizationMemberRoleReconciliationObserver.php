<?php

namespace App\Observers\Organization;

use App\Models\OrganizationMember;
use App\Services\Organization\UserRoleReconciler;

/**
 * Item 22 do backlog gap-simplesvet — "Nota de coordenação" do contrato de API. Antes deste
 * observer, `UserRoleReconciler::reconcile()` só rodava porque cada serviço de escrita
 * (`OrganizationMemberService`, `OrganizationInvitationService`, `AuthController`,
 * `RegistrationCompletionService`) se lembrava de chamá-lo manualmente — disciplina, não
 * invariante. Uma fixture de teste que cria `OrganizationMember` direto (factory, sem passar
 * pelo fluxo de convite) nunca reconciliava, e o membro ficava sem o papel Spatie do cargo —
 * rota protegida por `permission:...` barrava quem a Policy já deixaria passar.
 *
 * Reconciliar em dobro (aqui + na chamada manual que já existe em `OrganizationMemberService`)
 * é seguro: `UserRoleReconciler::reconcile()` é idempotente, recalcula o conjunto inteiro a
 * cada chamada.
 */
final class OrganizationMemberRoleReconciliationObserver
{
    public function __construct(private readonly UserRoleReconciler $reconciler) {}

    public function created(OrganizationMember $member): void
    {
        $this->reconcile($member);
    }

    public function updated(OrganizationMember $member): void
    {
        if (! $member->wasChanged(['role', 'is_active'])) {
            return;
        }

        $this->reconcile($member);
    }

    private function reconcile(OrganizationMember $member): void
    {
        $member->loadMissing('user');

        if ($member->user !== null) {
            $this->reconciler->reconcile($member->user);
        }
    }
}
