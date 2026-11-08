<?php

namespace App\DataTransferObjects\Fiscal;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Dono de um registro fiscal (perfil, sequência, inutilização, documento) — a regra de
 * `CommercialScopeResolver`: com organização, ela é a dona; sem, o profissional (vet volante).
 */
final readonly class FiscalOwner
{
    /**
     * `professionalId` nulo só no dono-organização montado sem um usuário (contingência,
     * configuração): o escopo usa a organização e a linha nova grava `professional_id` nulo.
     */
    public function __construct(public ?int $organizationId, public ?int $professionalId) {}

    public static function of(Model $record): self
    {
        return new self($record->getAttribute('organization_id'), $record->getAttribute('professional_id'));
    }

    /** Dono organização: o escopo usa só a organização (o profissional não entra no filtro). */
    public static function forOrganization(int $organizationId): self
    {
        return new self($organizationId, null);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scope(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        if ($this->organizationId !== null) {
            return $query->where("{$table}.organization_id", $this->organizationId);
        }

        return $query->whereNull("{$table}.organization_id")->where("{$table}.professional_id", $this->professionalId);
    }

    /** @return array{organization_id: int|null, professional_id: int|null} */
    public function attributes(): array
    {
        return ['organization_id' => $this->organizationId, 'professional_id' => $this->professionalId];
    }
}
