<?php

namespace App\Models;

use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Comissão a recuperar do profissional (PLANO 2.3): item de fechamento estornado com o
 * recebimento. Os próximos fechamentos do mesmo vínculo (`staff_id`, que é de uma organização)
 * descontam até zerar (`CommissionSettlementDeduction`).
 */
class CommissionClawback extends Model
{
    protected $fillable = [
        'organization_id',
        'professional_id',
        'staff_id',
        'commission_settlement_item_id',
        'sale_item_id',
        'sold_package_session_id',
        'amount',
        'reason',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function soldPackageSession(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(SoldPackageSession::class);
    }

    public function deductions(): HasMany
    {
        return $this->hasMany(CommissionSettlementDeduction::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    /** Ainda a descontar. Exige `deductions` carregadas (ou consulta). */
    public function pendingAmount(): Money
    {
        return Money::of($this->amount)->minus(Money::sum($this->deductions->pluck('amount')));
    }

    /**
     * Com saldo a descontar — subconsulta, para filtrar e travar (`lockForUpdate`) no banco.
     *
     * @param  Builder<self>  $query
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereRaw('commission_clawbacks.amount > (select coalesce(sum(d.amount), 0) from commission_settlement_deductions d where d.commission_clawback_id = commission_clawbacks.id)');
    }
}
