<?php

namespace App\Services\Inventory;

use App\Models\Inventory;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * O estoque é da ORGANIZAÇÃO quando ela existe, nunca do profissional isolado — um vet volante
 * sem organização continua com estoque individual (docs/vinculo-estoque-aplicacao-clinica.md
 * item 5). Único ponto de verdade para "quem enxerga/decrementa qual item de `inventories`":
 * usado tanto por `InventoryController` (CRUD) quanto por `ClinicalStockDeductionService`
 * (baixa por vacina/vermífugo) — duas superfícies, uma regra só.
 */
final class InventoryScopeResolver
{
    /**
     * REGRESSÃO corrigida em 2026-09-15 (relatada em produção/dev): quem tem organização ativa
     * via a migration `2026_09_14_100100_backfill_organization_id_on_commercial_tables` (que só
     * rodou UMA VEZ). Toda linha comercial criada DEPOIS daquele backfill e ANTES do escopo por
     * organização entrar em vigor nasceu órfã — `organization_id` NULL com `professional_id` de
     * um dono que JÁ tinha organização. Sem a segunda cláusula abaixo, essa linha some: o ramo
     * de organização não pega (`organization_id` é null) e o ramo de freelancer nunca era
     * alcançado (`isNotEmpty()` já tinha desviado).
     *
     * A rede de segurança (`orWhere` com o próprio `professional_id`) cobre dois casos que o
     * backfill (`CommercialOrganizationBackfillService`, reexecutado em
     * `2026_09_15_120000_rerun_commercial_organization_backfill.php`) não resolve de forma
     * automática: (1) qualquer órfão residual criado numa janela futura equivalente, e (2) dono
     * de MAIS de uma organização — o backfill deixa esse caso null DE PROPÓSITO, por não
     * conseguir inferir a qual das organizações a linha pertence, e isso é permanente, não uma
     * questão de tempo. Sem a rede de segurança, esse segundo caso ficaria invisível para sempre,
     * mesmo depois de qualquer backfill.
     *
     * Exposição aceita: a linha só aparece quando `professional_id` é o PRÓPRIO usuário — nunca
     * o item de outro colega. Um ex-membro que saiu da organização já enxergava as próprias
     * linhas órfãs por essa mesma regra antes desta correção (ramo de freelancer, inalterado);
     * a novidade aqui é só que um membro ATIVO deixa de perder de vista o que ele mesmo
     * cadastrou antes de a organização existir/ser vinculada.
     *
     * @return Builder<Inventory>
     */
    public function scopeQuery(Builder $query, User $user): Builder
    {
        $organizationIds = $this->activeOrganizationIds($user);

        if ($organizationIds->isEmpty()) {
            return $query->where('professional_id', $user->id)->whereNull('organization_id');
        }

        return $query->where(function (Builder $scoped) use ($organizationIds, $user): void {
            $scoped->whereIn('organization_id', $organizationIds)
                ->orWhere(function (Builder $ownOrphan) use ($user): void {
                    $ownOrphan->where('professional_id', $user->id)->whereNull('organization_id');
                });
        });
    }

    public function userCanAccess(Inventory $inventory, User $user): bool
    {
        if ($inventory->organization_id !== null) {
            return $this->activeOrganizationIds($user)->contains($inventory->organization_id);
        }

        return $inventory->professional_id === $user->id;
    }

    /**
     * Organização usada para gravar `inventories.organization_id` num item novo — mesma
     * prioridade (owner primeiro) de `User::primaryMembershipPerOrganization()`. `null` para
     * quem não tem nenhum vínculo ativo (vet volante), que é o resultado correto.
     */
    public function primaryOrganizationId(User $user): ?int
    {
        return $user->primaryMembershipPerOrganization()->first()?->organization_id;
    }

    /**
     * @return Collection<int, int>
     */
    private function activeOrganizationIds(User $user): Collection
    {
        return $user->activeOrganizationMemberships()->pluck('organization_id');
    }
}
