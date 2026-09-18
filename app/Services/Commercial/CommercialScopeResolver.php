<?php

namespace App\Services\Commercial;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * "Quem enxerga qual linha do grupo COMERCIAL" — produto, grupo, marca, forma de pagamento,
 * conta, caixa e venda. Generaliza a regra que `InventoryScopeResolver` já provou em
 * `inventories`, para não reescrever a mesma cláusula em cada controller novo dos docs 01/04/08.
 *
 * `InventoryScopeResolver` NÃO foi substituído por este: ele é tipado em `Inventory`, tem teste
 * próprio e carrega a documentação da regressão de 2026-09-15. Aqui a mesma regra aparece
 * genérica; lá ela continua específica. Fundir os dois é trabalho de uma tarefa própria, não
 * efeito colateral desta.
 *
 * A regra, em uma frase: com organização ativa, você vê o que é da organização MAIS o que é
 * seu e ainda não tem organização (órfão); sem nenhuma organização (vet volante), você vê só
 * o que é seu e órfão.
 *
 * @see \App\Services\Inventory\InventoryScopeResolver para o relato completo do porquê da
 *      segunda cláusula (`orWhere` do órfão próprio) existir.
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
     * @return Collection<int, int>
     */
    private function activeOrganizationIds(User $user): Collection
    {
        return $user->activeOrganizationMemberships()->pluck('organization_id');
    }
}
