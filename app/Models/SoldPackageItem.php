<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Saldo por serviço dentro de um pacote vendido. Contrato
 * docs/gap-simplesvet/specs/10-pacotes-de-servicos-vendidos-spec.md.
 */
class SoldPackageItem extends Model
{
    protected $fillable = [
        'sold_package_id',
        'service_id',
        'quantity_total',
        'quantity_used',
    ];

    protected $casts = [
        'quantity_total' => 'integer',
        'quantity_used' => 'integer',
    ];

    public function soldPackage(): BelongsTo
    {
        return $this->belongsTo(SoldPackage::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function consumptions(): HasMany
    {
        return $this->hasMany(SoldPackageConsumption::class);
    }

    public function quantityRemaining(): int
    {
        return $this->quantity_total - $this->quantity_used;
    }

    /**
     * Dá baixa de `$quantity` sessões. `SoldPackageService::consume()` já validou o saldo —
     * este método só grava, para não duplicar a regra em dois lugares.
     */
    public function registerConsumption(int $quantity): void
    {
        $this->increment('quantity_used', $quantity);
    }
}
