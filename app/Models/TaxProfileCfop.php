<?php

namespace App\Models;

use App\Enums\FiscalCfopOperation;
use App\Enums\FiscalCfopScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uma faixa de CFOP do perfil: (operação, dentro/fora do estado) → CFOP. */
class TaxProfileCfop extends Model
{
    protected $fillable = ['tax_profile_id', 'operation', 'scope', 'cfop'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'operation' => FiscalCfopOperation::class,
            'scope' => FiscalCfopScope::class,
        ];
    }

    public function taxProfile(): BelongsTo
    {
        return $this->belongsTo(TaxProfile::class);
    }
}
