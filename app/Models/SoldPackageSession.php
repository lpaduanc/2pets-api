<?php

namespace App\Models;

use App\Enums\SoldPackageSessionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Uma sessão de pacote vendido com o valor congelado na venda (PLANO 2.7). É a unidade da
 * receita por competência (a DRE gerencial reconhece `amount` na baixa), da comissão da sessão
 * (`staff_id` = quem executou) e da rentabilidade da sessão (`unit_cost` do serviço na baixa).
 */
class SoldPackageSession extends Model
{
    protected $fillable = [
        'sold_package_id',
        'sold_package_item_id',
        'sequence',
        'amount',
        'status',
        'sold_package_consumption_id',
        'consumed_at',
        'staff_id',
        'unit_cost',
        'resolved_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'amount' => 'decimal:2',
            'status' => SoldPackageSessionStatus::class,
            'consumed_at' => 'datetime',
            'unit_cost' => 'decimal:4',
            'resolved_at' => 'datetime',
        ];
    }

    public function soldPackage(): BelongsTo
    {
        return $this->belongsTo(SoldPackage::class);
    }

    public function soldPackageItem(): BelongsTo
    {
        return $this->belongsTo(SoldPackageItem::class);
    }

    public function consumption(): BelongsTo
    {
        return $this->belongsTo(SoldPackageConsumption::class, 'sold_package_consumption_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(OrganizationMember::class, 'staff_id');
    }

    /** "Banho · sessão 2 (pacote 4 banhos)". Exige `soldPackageItem.service` e `soldPackage.servicePackage`. */
    public function description(): string
    {
        return sprintf('%s · sessão %d (pacote %s)', $this->soldPackageItem?->service?->name ?? 'Serviço', $this->sequence, $this->soldPackage?->servicePackage?->name ?? '');
    }

    /** Fechamento de comissão que vale (o estornado fica no histórico), como em `SaleItem`. */
    public function commissionSettlementItem(): HasOne
    {
        return $this->hasOne(CommissionSettlementItem::class)->whereNull('reversed_at');
    }
}
