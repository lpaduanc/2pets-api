<?php

namespace App\Http\Requests\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * "Em nome de quem esta janela de agenda/bloqueio está sendo criada" — `professional_id`
 * no payload é OPCIONAL: ausente, é a própria pessoa autenticada; presente, só é aceito
 * quando quem está autenticado é dono da organização à qual o profissional-alvo pertence
 * (`AvailabilityPolicy`/`BlockedTimePolicy::manageFor()`).
 *
 * Compartilhado entre `Availability` e `BlockedTime` porque as duas tabelas têm exatamente
 * a mesma regra de "quem pode escrever a agenda de quem" (ver docblock das duas Policies).
 */
trait ResolvesTargetProfessional
{
    private ?User $resolvedTargetProfessional = null;

    public function targetProfessionalId(): int
    {
        return $this->filled('professional_id')
            ? (int) $this->input('professional_id')
            : (int) $this->user()->id;
    }

    /**
     * O `User` completo por trás de `targetProfessionalId()` — reaproveitado pelo
     * controller depois que `authorize()` já validou o acesso, para não repetir a consulta.
     */
    public function targetProfessional(): User
    {
        return $this->resolvedTargetProfessional ??= $this->targetProfessionalId() === $this->user()->id
            ? $this->user()
            : User::findOrFail($this->targetProfessionalId());
    }

    /**
     * @param  class-string  $policySubjectClass
     */
    protected function authorizeManagingTarget(string $policySubjectClass): bool
    {
        if ($this->targetProfessionalId() === $this->user()->id) {
            return true;
        }

        $target = User::find($this->targetProfessionalId());

        return $target !== null && Gate::forUser($this->user())->allows('manageFor', [$policySubjectClass, $target]);
    }

    protected function organizationIdForTarget(): ?int
    {
        $targetProfessionalId = $this->targetProfessionalId();

        if ($targetProfessionalId === $this->user()->id) {
            return $this->user()->activeOrganizationId();
        }

        return User::find($targetProfessionalId)?->activeOrganizationId();
    }
}
