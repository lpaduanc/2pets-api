<?php

namespace App\Services\Commercial;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * "Quem enxerga qual linha do grupo COMERCIAL" — produto (inclusive o antigo insumo clínico,
 * consolidado dentro de `Product`), grupo, marca, forma de pagamento, conta, caixa e venda.
 *
 * A regra, em uma frase: com organização ativa, você vê o que é da organização MAIS o que é
 * seu e ainda não tem organização (órfão); sem nenhuma organização (vet volante), você vê só
 * o que é seu e órfão.
 *
 * Generalizou uma regra que nasceu em `InventoryScopeResolver` (removido na consolidação de
 * estoque, docs/gap-simplesvet/specs/produtos-estoque-consolidado-spec.md — a regressão de
 * 2026-09-15 que motivou a segunda cláusula abaixo, `orWhere` do órfão próprio, está descrita
 * em `docs/vinculo-estoque-aplicacao-clinica.md` item 5).
 */
final class CommercialScopeResolver
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scopeQuery(Builder $query, User $user): Builder
    {
        $organizationIds = $this->activeOrganizationIds($user);
        $table = $query->getModel()->getTable();

        if ($organizationIds->isEmpty()) {
            return $query->where("{$table}.professional_id", $user->id)
                ->whereNull("{$table}.organization_id");
        }

        return $query->where(function (Builder $scoped) use ($organizationIds, $user, $table): void {
            $scoped->whereIn("{$table}.organization_id", $organizationIds)
                ->orWhere(function (Builder $ownOrphan) use ($user, $table): void {
                    $ownOrphan->where("{$table}.professional_id", $user->id)
                        ->whereNull("{$table}.organization_id");
                });
        });
    }

    /**
     * O mesmo teste de `scopeQuery`, aplicado a um registro já carregado. Existe para os
     * caminhos que recebem o model por route model binding e não passam pela query escopada
     * — sem ele, `findOrFail($id)` seria um IDOR aberto.
     */
    public function userCanAccess(Model $record, User $user): bool
    {
        if ($record->getAttribute('organization_id') !== null) {
            return $this->activeOrganizationIds($user)->contains($record->getAttribute('organization_id'));
        }

        return $record->getAttribute('professional_id') === $user->id;
    }

    /**
     * Organização gravada num registro NOVO — mesma prioridade (owner primeiro) de
     * `User::primaryMembershipPerOrganization()`. `null` para vet volante, que é o correto.
     */
    public function primaryOrganizationId(User $user): ?int
    {
        return $user->primaryMembershipPerOrganization()->first()?->organization_id;
    }

    public function primaryOrganization(User $user): ?Organization
    {
        $id = $this->primaryOrganizationId($user);

        return $id === null ? null : Organization::find($id);
    }

    /**
     * Par `organization_id`/`professional_id` para gravar num registro novo. Os dois juntos,
     * sempre: `professional_id` é quem cadastrou (e o que mantém o registro visível se a
     * organização for desvinculada depois), `organization_id` é de quem o registro é.
     *
     * @return array{organization_id: int|null, professional_id: int}
     */
    public function ownershipFor(User $user): array
    {
        return [
            'organization_id' => $this->primaryOrganizationId($user),
            'professional_id' => $user->id,
        ];
    }

    /**
     * Vínculos ativos (`organization_members`) das organizações do usuário — quem pode ser o
     * "funcionário responsável" de um item de venda (doc 01 → comissão do doc 09). Vet volante
     * não tem equipe: coleção vazia.
     *
     * @return EloquentCollection<int, OrganizationMember>
     */
    public function teamMembers(User $user): EloquentCollection
    {
        $organizationIds = $this->activeOrganizationIds($user);

        if ($organizationIds->isEmpty()) {
            return new EloquentCollection;
        }

        return OrganizationMember::query()
            ->whereIn('organization_id', $organizationIds)
            ->where('is_active', true)
            ->with('user:id,name')
            ->get();
    }

    /**
     * Ids de `users` da equipe, incluindo o próprio usuário — a base de "cliente de alguém da
     * clínica" (`ProfessionalClientsQuery::queryForAny`).
     *
     * @return list<int>
     */
    public function teamUserIds(User $user): array
    {
        return $this->teamMembers($user)
            ->pluck('user_id')
            ->push($user->id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Pode operar o balcão (abrir caixa, vender)? Profissional cadastrado ou membro ativo de
     * alguma organização (a recepcionista pode ter conta de tutor). Tutor puro não: o módulo
     * grava dado financeiro real, e sem esta trava a conta de um tutor ganhava caixa e formas
     * de pagamento órfãs.
     */
    public function canOperateCounter(User $user): bool
    {
        return $user->role === 'professional' || $this->activeOrganizationIds($user)->isNotEmpty();
    }

    /**
     * @return Collection<int, int>
     */
    private function activeOrganizationIds(User $user): Collection
    {
        return $user->activeOrganizationMemberships()->pluck('organization_id');
    }
}
