<?php

namespace App\Enums;

/**
 * Estado do vínculo entre um veterinário e um pet, do ponto de vista de "posso solicitar
 * acesso a este pet?".
 *
 * Os valores espelham `pet_vet_accesses.status` (constantes `PetVetAccess::STATUS_*`), mais o
 * caso sintético `NONE` — "nunca houve vínculo", que não existe como linha no banco mas é um
 * estado legítimo da resposta da busca. `VetAccessStateMatchesModelConstantsTest` trava esse
 * espelhamento para que uma das duas pontas não mude sozinha.
 */
enum VetAccessState: string
{
    case NONE = 'none';
    case PENDING = 'pending';
    case ACCEPTED = 'accepted';
    case REJECTED = 'rejected';
    case REVOKED = 'revoked';
    case SUPERSEDED = 'superseded';

    public static function fromStatus(?string $status): self
    {
        return $status === null ? self::NONE : (self::tryFrom($status) ?? self::NONE);
    }

    /**
     * Solicitação nova só faz sentido quando não há vínculo vivo. `rejected`, `revoked` e
     * `superseded` são terminais e liberam nova tentativa — é a mesma regra dos índices
     * parciais `pet_vet_access_pending_unique`/`pet_vet_access_accepted_unique`, que só
     * cobrem `pending` e `accepted`.
     *
     * `accepted` bloqueia apenas solicitação do MESMO nível ou menor; upgrade é legítimo e
     * quem decide isso é `PetVetAccessSnapshot::canRequest()`, com o nível concedido em mãos.
     */
    public function allowsNewRequest(): bool
    {
        return match ($this) {
            self::NONE, self::REJECTED, self::REVOKED, self::SUPERSEDED => true,
            self::PENDING, self::ACCEPTED => false,
        };
    }

    /** Texto curto para o vet, em pt-BR. */
    public function label(): string
    {
        return match ($this) {
            self::NONE => 'Sem acesso',
            self::PENDING => 'Aguardando o tutor',
            self::ACCEPTED => 'Acesso liberado',
            self::REJECTED => 'Solicitação recusada',
            self::REVOKED => 'Acesso revogado',
            self::SUPERSEDED => 'Nível de acesso substituído',
        };
    }
}
