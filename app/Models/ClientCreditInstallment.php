<?php

namespace App\Models;

use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Parcela do crediário (PLANO 2.2). `status` gravado: `open` · `paid` · `cancelled`; o que a tela
 * mostra (a vencer, vencida, paga, parcial) é derivado em `displayStatus`.
 */
class ClientCreditInstallment extends Model
{
    public const OPEN = 'open';

    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    protected $fillable = ['client_credit_plan_id', 'number', 'due_date', 'amount', 'paid_principal', 'paid_fees', 'fees_settled_on', 'open_fees_paid', 'status'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'fees_settled_on' => 'date',
            'amount' => 'decimal:2',
            'paid_principal' => 'decimal:2',
            'paid_fees' => 'decimal:2',
            'open_fees_paid' => 'decimal:2',
            'number' => 'integer',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ClientCreditPlan::class, 'client_credit_plan_id');
    }

    public function remainingPrincipal(): Money
    {
        return Money::of($this->amount)->minus($this->paid_principal);
    }

    /** `paid` · `cancelled` · `overdue` (vencida, mesmo parcial) · `partial` · `upcoming`. */
    public function displayStatus(\DateTimeInterface $today): string
    {
        return match (true) {
            $this->status === self::CANCELLED => 'cancelled',
            ! $this->remainingPrincipal()->isPositive() => 'paid',
            $this->due_date->lt($today) => 'overdue',
            Money::of($this->paid_principal)->isPositive() => 'partial',
            default => 'upcoming',
        };
    }

    /** Estado no formato da `ClientCreditCalculator`. @return array<string, mixed> */
    public function toCalculatorState(): array
    {
        return [
            'id' => $this->id,
            'amount' => (string) $this->amount,
            'paid_principal' => (string) $this->paid_principal,
            'due_date' => $this->due_date->toDateString(),
            'fees_settled_on' => $this->fees_settled_on?->toDateString(),
            'open_fees_paid' => (string) $this->open_fees_paid,
            'policy' => $this->plan->policy(),
        ];
    }
}
