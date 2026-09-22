<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Composição do pacote de serviços — "10 banhos", "5 sessões de fisioterapia". Contrato
 * docs/gap-simplesvet/specs/10-pacotes-de-servicos-vendidos-spec.md.
 */
class ServicePackageItem extends Model
{
    protected $fillable = [
        'service_package_id',
        'service_id',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function servicePackage(): BelongsTo
    {
        return $this->belongsTo(ServicePackage::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
