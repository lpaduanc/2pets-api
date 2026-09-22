<?php

namespace App\Models;

use App\Enums\SoldPackageStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Instância vendida de um pacote de serviços — o crédito de sessões de UM pet. Contrato
 * docs/gap-simplesvet/specs/10-pacotes-de-servicos-vendidos-spec.md.
 *
 * `status` só é gravado como `cancelled`. O status que a tela mostra é sempre o EFETIVO
 * (`effectiveStatus()`), no mesmo padrão de `Sale::effectiveQuoteStatus()` — sem job/scheduler
 * reescrevendo a coluna quando o prazo vence (não há scheduler rodando em dev, `CLAUDE.md`).
 */
class SoldPackage extends Model
{
    protected $fillable = [
        'organization_id',
        'professional_id',
        'sale_id',
        'sale_item_id',
        'service_package_id',
        'client_id',
        'pet_id',
        'sold_at',
        'expires_at',
        'status',
        'cancelled_at',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SoldPackageStatus::class,
            'sold_at' => 'datetime',
            'expires_at' => 'date',
            'cancelled_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function servicePackage(): BelongsTo
    {
        return $this->belongsTo(ServicePackage::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SoldPackageItem::class);
    }

    /** Soma de saldo restante entre todos os serviços do pacote. */
    public function totalRemaining(): int
    {
        return (int) $this->items->sum(fn (SoldPackageItem $item): int => $item->quantityRemaining());
    }

    public function isPastValidity(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lt(today());
    }

    /**
     * Status que o pacote TEM de fato hoje: cancelado prevalece sobre tudo; sem saldo é
     * consumido mesmo com validade no futuro; vencido com saldo > 0 é expirado; caso
     * contrário, ativo.
     */
    public function effectiveStatus(): SoldPackageStatus
    {
        if ($this->status === SoldPackageStatus::CANCELLED) {
            return SoldPackageStatus::CANCELLED;
        }

        if ($this->totalRemaining() <= 0) {
            return SoldPackageStatus::CONSUMED;
        }

        if ($this->isPastValidity()) {
            return SoldPackageStatus::EXPIRED;
        }

        return SoldPackageStatus::ACTIVE;
    }

    public function canBeConsumed(): bool
    {
        return $this->effectiveStatus() === SoldPackageStatus::ACTIVE;
    }

    /**
     * Filtro pelo status EFETIVO, em SQL — mesma regra de `effectiveStatus()`. Exige
     * `sold_package_items` já somável via subquery, porque não há coluna de saldo cacheada.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWhereEffectiveStatus(Builder $query, SoldPackageStatus $status): Builder
    {
        $table = $query->getModel()->getTable();
        $hasBalance = fn (Builder $q): Builder => $q->whereExists($this->balanceSubquery($table));

        return match ($status) {
            SoldPackageStatus::CANCELLED => $query->where("{$table}.status", SoldPackageStatus::CANCELLED->value),
            SoldPackageStatus::CONSUMED => $query
                ->where("{$table}.status", '!=', SoldPackageStatus::CANCELLED->value)
                ->whereNot($hasBalance),
            SoldPackageStatus::EXPIRED => $query
                ->where("{$table}.status", '!=', SoldPackageStatus::CANCELLED->value)
                ->where($hasBalance)
                ->whereNotNull("{$table}.expires_at")
                ->whereDate("{$table}.expires_at", '<', today()),
            SoldPackageStatus::ACTIVE => $query
                ->where("{$table}.status", '!=', SoldPackageStatus::CANCELLED->value)
                ->where($hasBalance)
                ->where(fn (Builder $q) => $q
                    ->whereNull("{$table}.expires_at")
                    ->orWhereDate("{$table}.expires_at", '>=', today())),
        };
    }

    /**
     * `whereExists()` invoca o callback com o QUERY builder base (`Illuminate\Database\Query\
     * Builder`), nunca com o Eloquent `Builder` — tipar o parâmetro como Eloquent (achado desta
     * auditoria) quebrava com `TypeError` em runtime, silenciosamente nunca exercitado porque
     * os testes deste doc nunca tinham rodado.
     *
     * @return \Closure(\Illuminate\Database\Query\Builder): void
     */
    private function balanceSubquery(string $table): \Closure
    {
        return function (\Illuminate\Database\Query\Builder $sub) use ($table): void {
            $sub->from('sold_package_items')
                ->whereColumn('sold_package_items.sold_package_id', "{$table}.id")
                ->whereRaw('quantity_total - quantity_used > 0');
        };
    }
}
