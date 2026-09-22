<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Transferência entre contas da própria clínica — contrato
 * docs/gap-simplesvet/02-financeiro-plano-de-contas-dre.md. Nunca aparece na DRE: não é
 * receita nem despesa.
 */
class FinancialTransfer extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'professional_id',
        'from_account_id',
        'to_account_id',
        'amount',
        'occurred_at',
        'description',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'occurred_at' => 'datetime',
        ];
    }

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'from_account_id');
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'to_account_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
