<?php

namespace App\DataTransferObjects;

use App\Enums\VetAccessLevel;
use App\Enums\VetAccessState;
use App\Models\PetVetAccess;

/**
 * Estado do vínculo de UM veterinário com UM pet, do ponto de vista de "o que eu posso fazer
 * agora?". É o que a busca (`GET /pets/search`) devolve em `vet_access`.
 *
 * Desde que o tutor passou a decidir o nível, um mesmo par (pet, vet) pode ter DUAS linhas
 * vivas ao mesmo tempo: o acesso já concedido (`accepted`) e um pedido de upgrade aguardando
 * resposta (`pending`). Colapsar isso numa linha só — como a busca fazia — escondia
 * exatamente a informação nova.
 */
final readonly class PetVetAccessSnapshot
{
    public function __construct(
        public ?PetVetAccess $granted,
        public ?PetVetAccess $pending,
        public ?PetVetAccess $latest,
    ) {}

    /**
     * @param  list<PetVetAccess>  $accesses  linhas do par (pet, vet), mais recente primeiro
     */
    public static function fromAccesses(array $accesses): self
    {
        $matching = static fn (string $status) => static fn (PetVetAccess $access): bool => $access->status === $status;

        return new self(
            granted: self::firstMatching($accesses, $matching(PetVetAccess::STATUS_ACCEPTED)),
            pending: self::firstMatching($accesses, $matching(PetVetAccess::STATUS_PENDING)),
            latest: $accesses[0] ?? null,
        );
    }

    public static function empty(): self
    {
        return new self(granted: null, pending: null, latest: null);
    }

    /**
     * `accepted` tem precedência sobre `pending` porque descreve o acesso EFETIVO: o vet com
     * `read` concedido e upgrade em análise continua enxergando o prontuário. O pedido em voo
     * aparece em `pendingRequestLevel()`.
     */
    public function state(): VetAccessState
    {
        return VetAccessState::fromStatus($this->representative()?->status);
    }

    public function grantedLevel(): ?VetAccessLevel
    {
        return $this->granted?->access_level;
    }

    public function pendingRequestLevel(): ?VetAccessLevel
    {
        return $this->pending?->requested_access_level;
    }

    /**
     * Solicitar faz sentido quando não há pedido em voo E ou não existe concessão, ou a
     * concessão existente ainda tem nível acima dela para pedir.
     */
    public function canRequest(): bool
    {
        if ($this->pending !== null) {
            return false;
        }

        if ($this->granted === null) {
            return $this->state()->allowsNewRequest();
        }

        return $this->grantedLevel()?->allowsUpgrade() ?? true;
    }

    public function accessId(): ?int
    {
        return $this->representative()?->id;
    }

    public function since(): ?string
    {
        $access = $this->representative();

        return ($access?->granted_at ?? $access?->requested_at)?->toDateString();
    }

    private function representative(): ?PetVetAccess
    {
        return $this->granted ?? $this->pending ?? $this->latest;
    }

    /**
     * @param  list<PetVetAccess>  $accesses
     * @param  callable(PetVetAccess): bool  $predicate
     */
    private static function firstMatching(array $accesses, callable $predicate): ?PetVetAccess
    {
        foreach ($accesses as $access) {
            if ($predicate($access)) {
                return $access;
            }
        }

        return null;
    }
}
