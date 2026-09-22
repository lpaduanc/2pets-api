<?php

namespace App\Models;

use App\Enums\HolidayScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Feriado cadastrado pela organização/profissional — item 23 do backlog gap-simplesvet.
 * Consumido pela agenda (item 21) para bloquear slot de agendamento no dia, e pelo financeiro
 * (item 03, `DueDateService::nextBusinessDay()`) para o "próximo dia útil" de vencimento.
 *
 * `scope`/`uf`/`city_ibge_code` vieram da consolidação com `company_holidays` (item 03) — as
 * duas tabelas modelavam o mesmo conceito. `scope = national` com `organization_id` E
 * `professional_id` nulos é a ÚNICA exceção à convenção "sempre um dono" dos catálogos do item
 * 23 (`HolidaySeeder`, nunca criado pelo `CatalogController` genérico): o feriado nacional vale
 * para toda clínica que não tiver sobrescrita própria.
 */
class Holiday extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'professional_id',
        'name',
        'date',
        'recurring_annually',
        'active',
        'scope',
        'uf',
        'city_ibge_code',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'recurring_annually' => 'boolean',
            'active' => 'boolean',
            'scope' => HolidayScope::class,
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * Nacional/global (`organization_id` e `professional_id` nulos) ou o próprio dono
     * informado — nunca o feriado de outra clínica. Usado por `DueDateService`, que precisa
     * do feriado nacional MAIS o próprio, ao contrário de `CommercialScopeResolver::scopeQuery()`
     * (genérico, não enxerga linha nenhuma com os dois nulos).
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, ?int $organizationId, ?int $professionalId = null): Builder
    {
        return $query->where(function (Builder $q) use ($organizationId, $professionalId): void {
            $q->where(fn (Builder $global) => $global->whereNull('organization_id')->whereNull('professional_id'));

            if ($organizationId !== null) {
                $q->orWhere('organization_id', $organizationId);
            } elseif ($professionalId !== null) {
                $q->orWhere(fn (Builder $own) => $own->where('professional_id', $professionalId)->whereNull('organization_id'));
            }
        });
    }

    /**
     * Verdadeiro se este feriado cobre a data informada — considera repetição anual
     * (mês/dia, ignorando o ano cadastrado) quando `recurring_annually` está ligado.
     */
    public function coversDate(\DateTimeInterface $date): bool
    {
        if ($this->recurring_annually) {
            return $this->date->format('m-d') === $date->format('m-d');
        }

        return $this->date->isSameDay($date);
    }
}
