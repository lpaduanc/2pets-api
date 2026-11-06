<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Log de prorrogação de validade de pacote vendido (PLANO 2.7): de/para, motivo e quem. */
class SoldPackageValidityExtension extends Model
{
    protected $fillable = ['sold_package_id', 'from_date', 'to_date', 'reason', 'user_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['from_date' => 'date', 'to_date' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
