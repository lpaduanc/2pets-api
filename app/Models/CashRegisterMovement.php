<?php

namespace App\Models;

use App\Enums\CashMovementType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Movimento do caixa — livro-razão append-only. Nunca editar nem apagar: correção é um
 * movimento de ajuste novo. Ver a migration para o porquê.
 */
class CashRegisterMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'cash_register_id',
        'type',
        'payment_method_id',
        'account_id',
        'amount',
        'occurred_at',
        'description',
        'user_id',
        'reference_type',
        'reference_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CashMovementType::class,
            'amount' => 'decimal:2',
            'occurred_at' => 'datetime',
        ];
    }

    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'account_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /** Valor com sinal — a direção vem do tipo, nunca da coluna (CHECK garante `amount > 0`). */
    public function signedAmount(): float
    {
        return round((float) $this->amount * $this->type->signFor(), 2);
    }

    /**
     * Movimento que mexe na GAVETA física. Suprimento e sangria são sempre dinheiro (é o que
     * são); os demais só quando a forma de pagamento é dinheiro.
     */
    public function isPhysicalCash(): bool
    {
        if (in_array($this->type, [CashMovementType::SUPPLY, CashMovementType::WITHDRAWAL], true)) {
            return true;
        }

        return $this->paymentMethod?->kind->isPhysicalCash() ?? false;
    }

    /** @param  Builder<self>  $query */
    public function scopeOfType(Builder $query, CashMovementType $type): Builder
    {
        return $query->where('type', $type->value);
    }
}
