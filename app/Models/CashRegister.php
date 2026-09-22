<?php

namespace App\Models;

use App\Enums\CashMovementType;
use App\Enums\CashRegisterStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Sessão de caixa — contrato docs/gap-simplesvet/01-caixa-pdv.md.
 *
 * Abre de manhã com um valor de troco, recebe movimentos o dia todo, fecha com a contagem da
 * gaveta e é encerrado por quem confere. O ciclo dos quatro estados está em
 * `App\Enums\CashRegisterStatus`.
 *
 * Toda mutação passa por `App\Services\Commercial\CashRegisterService` — o model não abre,
 * não fecha e não lança movimento sozinho.
 */
class CashRegister extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'organization_id',
        'professional_id',
        'name',
        'opened_by',
        'opened_at',
        'opening_amount',
        'closed_by',
        'closed_at',
        'closing_amount',
        'counted_amount',
        'difference',
        'closing_breakdown',
        'settled_by',
        'settled_at',
        'status',
        'notes',
        'review_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CashRegisterStatus::class,
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'settled_at' => 'datetime',
            'opening_amount' => 'decimal:2',
            'closing_amount' => 'decimal:2',
            'counted_amount' => 'decimal:2',
            'difference' => 'decimal:2',
            'closing_breakdown' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    public function movements(): HasMany
    {
        // `chaperone`: cada movimento carregado já conhece o seu caixa sem nova query — o
        // `CashRegisterMovementResource` precisa do status para decidir a conferência cega.
        return $this->hasMany(CashRegisterMovement::class)->chaperone('cashRegister');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /**
     * Saldo esperado em DINHEIRO na gaveta: entradas − saídas, contando só o que é dinheiro
     * vivo. Cartão e Pix não estão na gaveta e por isso não entram aqui — confundir os dois
     * faria toda conferência acusar sobra.
     *
     * `opening_amount` NÃO é somado à parte: `CashRegisterService::open()` já grava o troco de
     * abertura como movimento `supply`, e somar os dois contava o troco em dobro.
     */
    public function expectedCashAmount(): float
    {
        $movements = $this->relationLoaded('movements')
            ? $this->movements
            : $this->movements()->with('paymentMethod')->get();

        $net = $movements
            ->filter(fn (CashRegisterMovement $movement): bool => $movement->isPhysicalCash())
            ->sum(fn (CashRegisterMovement $movement): float => $movement->signedAmount());

        return round((float) $net, 2);
    }

    /**
     * Esperado por forma de pagamento — a base da conferência cega do fechamento. Chave é o
     * `payment_method_id`; movimento sem forma (ajuste manual) cai em `null`.
     *
     * @return array<int|string, float>
     */
    public function expectedByPaymentMethod(): array
    {
        $movements = $this->relationLoaded('movements')
            ? $this->movements
            : $this->movements()->get();

        $totals = [];

        foreach ($movements as $movement) {
            $key = $movement->payment_method_id ?? 'none';
            $totals[$key] = round(($totals[$key] ?? 0) + $movement->signedAmount(), 2);
        }

        return $totals;
    }

    public function totalSupplies(): float
    {
        return $this->sumOfType(CashMovementType::SUPPLY);
    }

    public function totalWithdrawals(): float
    {
        return $this->sumOfType(CashMovementType::WITHDRAWAL);
    }

    public function totalSales(): float
    {
        return $this->sumOfType(CashMovementType::SALE_RECEIPT);
    }

    public function isOpen(): bool
    {
        return $this->status === CashRegisterStatus::OPEN;
    }

    /** @param  Builder<self>  $query */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', CashRegisterStatus::OPEN->value);
    }

    /** @param  Builder<self>  $query */
    public function scopeOnDay(Builder $query, \DateTimeInterface $day): Builder
    {
        return $query->whereDate('opened_at', $day);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'opening_amount', 'counted_amount', 'difference', 'settled_by'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    private function sumOfType(CashMovementType $type): float
    {
        $movements = $this->relationLoaded('movements')
            ? $this->movements->where('type', $type)
            : $this->movements()->where('type', $type->value)->get();

        return round((float) $movements->sum('amount'), 2);
    }
}
