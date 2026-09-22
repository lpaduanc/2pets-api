<?php

namespace App\Models;

use App\Enums\ClientAccountEntryDirection;
use App\Enums\ClientAccountEntryType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Linha do extrato da conta corrente do cliente — APPEND-ONLY. Contrato
 * docs/gap-simplesvet/11-conta-corrente-do-cliente-spec.md.
 *
 * Nunca `update()`/`delete()` numa linha existente: correção é um lançamento de ajuste novo
 * (`ClientAccountEntryType::ADJUSTMENT_DEBIT`/`ADJUSTMENT_CREDIT`) — mesma disciplina de
 * `CashRegisterMovement`. `balance_after` fica gravado na própria linha para o extrato nunca
 * depender de recalcular tudo desde o início.
 */
class ClientAccountEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_client_account_id',
        'type',
        'direction',
        'amount',
        'balance_after',
        'reference_type',
        'reference_id',
        'occurred_at',
        'user_id',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ClientAccountEntryType::class,
            'direction' => ClientAccountEntryDirection::class,
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'occurred_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CompanyClientAccount::class, 'company_client_account_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Efeito assinado sobre o saldo — o mesmo cálculo usado para gravar `balance_after`. */
    public function signedAmount(): float
    {
        return (float) $this->amount * $this->direction->sign();
    }
}
