<?php

namespace App\Services\Fiscal\Calculation;

use App\Enums\TaxProfileSource;

/**
 * Cadeia de herança do perfil tributário — spec docs/gap-petflow/specs/fase4-fiscal-sem-envio.md
 * §3.3: produto → grupo direto → grupos pais (recursivo) → padrão do dono → nenhum. Pura: quem
 * chama (`TaxProfileResolver`) carrega tudo em lote e só pergunta aqui.
 *
 * Só perfil ATIVO conta (D4): um perfil inativo no meio da cadeia é pulado, não interrompe. A
 * subida pelos pais para em ciclo (dado ruim não trava a emissão num laço).
 */
final class TaxProfileChain
{
    /**
     * @param  array<int, array{parent_id: int|null, tax_profile_id: int|null}>  $groups  por id
     * @param  list<int>  $activeProfileIds
     */
    public function __construct(
        private readonly array $groups,
        private readonly array $activeProfileIds,
        private readonly ?int $defaultProfileId,
    ) {}

    /** @return array{0: int, 1: TaxProfileSource}|null */
    public function resolve(?int $ownProfileId, ?int $groupId): ?array
    {
        if ($this->isActive($ownProfileId)) {
            return [$ownProfileId, TaxProfileSource::PRODUCT];
        }

        return $this->fromGroups($groupId) ?? $this->fromDefault();
    }

    /**
     * Para o próprio grupo (resource do grupo): o perfil dele conta como "do grupo".
     *
     * @return array{0: int, 1: TaxProfileSource}|null
     */
    public function resolveGroup(int $groupId): ?array
    {
        return $this->fromGroups($groupId) ?? $this->fromDefault();
    }

    /** @return array{0: int, 1: TaxProfileSource}|null */
    private function fromGroups(?int $groupId): ?array
    {
        $visited = [];
        $source = TaxProfileSource::GROUP;

        while ($groupId !== null && isset($this->groups[$groupId]) && ! isset($visited[$groupId])) {
            $visited[$groupId] = true;
            $profileId = $this->groups[$groupId]['tax_profile_id'];

            if ($this->isActive($profileId)) {
                return [$profileId, $source];
            }

            $groupId = $this->groups[$groupId]['parent_id'];
            $source = TaxProfileSource::PARENT_GROUP;
        }

        return null;
    }

    /** @return array{0: int, 1: TaxProfileSource}|null */
    private function fromDefault(): ?array
    {
        return $this->isActive($this->defaultProfileId) ? [$this->defaultProfileId, TaxProfileSource::DEFAULT] : null;
    }

    private function isActive(?int $profileId): bool
    {
        return $profileId !== null && in_array($profileId, $this->activeProfileIds, true);
    }
}
