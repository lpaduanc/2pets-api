<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Log de baixa de uma sessão de pacote — contrato
 * docs/gap-simplesvet/specs/10-pacotes-de-servicos-vendidos-spec.md. Cada linha é uma sessão
 * (ou grupo de sessões) efetivamente dada, com quem deu baixa e a origem (PDV, agenda ou
 * prontuário).
 */
class SoldPackageConsumption extends Model
{
    protected $fillable = [
        'sold_package_item_id',
        'appointment_id',
        'medical_record_id',
        'sale_item_id',
        'quantity',
        'consumed_at',
        'user_id',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'consumed_at' => 'datetime',
    ];

    public function soldPackageItem(): BelongsTo
    {
        return $this->belongsTo(SoldPackageItem::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
