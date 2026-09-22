<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Saldo cacheado da conta corrente de UM cliente em UMA clínica (ou com UM profissional
 * autônomo) — contrato docs/gap-simplesvet/11-conta-corrente-do-cliente-spec.md.
 *
 * `current_balance` é sempre reconciliável a partir de `client_account_entries`
 * (`SUM(credit) - SUM(debit)`); só existe aqui em cache para não somar o extrato inteiro a
 * cada leitura de saldo. Toda escrita passa por `App\Services\Commercial\ClientAccountService`
 * — nunca `->update(['current_balance' => ...])` direto, é assim que o cache e o extrato saem
 * de sincronia.
 */
class CompanyClientAccount extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'professional_id',
        'client_id',
        'credit_limit',
        'allow_credit_sale',
        'current_balance',
        'last_purchase_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
            'current_balance' => 'decimal:2',
            'allow_credit_sale' => 'boolean',
            'last_purchase_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(ClientAccountEntry::class);
    }

    /** Positivo = a clínica deve ao cliente. Negativo = o cliente deve à clínica (fiado). */
    public function isDebtor(): bool
    {
        return (float) $this->current_balance < 0;
    }

    /** Quanto ainda cabe em crédito antes de estourar `credit_limit`, nunca negativo. */
    public function availableCreditLimit(): float
    {
        $debt = max(0.0, -(float) $this->current_balance);

        return max(0.0, (float) $this->credit_limit - $debt);
    }
}
