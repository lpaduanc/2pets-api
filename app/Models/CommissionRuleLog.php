<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Auditoria de mudança de regra de comissão — contrato
 * docs/gap-simplesvet/specs/09-comissionamento-interno-repasses-spec.md, regra de negócio 6:
 * é o registro que resolve disputa entre funcionário e clínica sobre "quanto eu ia ganhar
 * quando vendi aquilo".
 */
class CommissionRuleLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'commission_rule_id',
        'changed_by',
        'field_changed',
        'old_value',
        'new_value',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function commissionRule(): BelongsTo
    {
        return $this->belongsTo(CommissionRule::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
