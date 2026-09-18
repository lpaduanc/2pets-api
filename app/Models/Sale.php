<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\FiscalOperation;
use App\Enums\SaleKind;
use App\Enums\SaleStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Venda de balcão (ou orçamento, quando `kind = quote`) — contrato
 * docs/gap-simplesvet/01-caixa-pdv.md.
 *
 * Não confundir com `Invoice` (fatura do atendimento veterinário) nem com `Order` (e-commerce):
 * as três coexistem de propósito, ver a migration.
 *
 * Toda mutação de valor passa por `App\Services\Commercial\SaleService`. Os totais aqui são
 * CACHE do que os itens somam — `recalculateTotals()` é o único lugar que os reescreve.
 */
class Sale extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'professional_id',
        'number',
        'client_id',
        'pet_id',
        'cash_register_id',
        'kind',
        'fiscal_operation',
        'status',
        'discount_type',
        'discount_value',
        'discount_amount',
        'subtotal',
        'total',
        'paid_amount',
        'printed_notes',
        'notes',
        'created_by',
        'sold_at',
        'cancelled_at',
        'cancellation_reason',
        'converted_to_sale_id',
        'valid_until',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => SaleKind::class,
            'fiscal_operation' => FiscalOperation::class,
            'status' => SaleStatus::class,
            'discount_type' => DiscountType::class,
            'discount_value' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'sold_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'valid_until' => 'date',
        ];
    }

    /** Relações que a consulta de vendas e o recibo precisam para não cair em N+1. */
    public const RESOURCE_RELATIONS = [
        'items.staff.user',
        'receipts.paymentMethod',
        'client',
        'pet',
        'cashRegister',
        'createdBy',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function convertedToSale(): BelongsTo
    {
        return $this->belongsTo(self::class, 'converted_to_sale_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(SaleReceipt::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashRegisterMovement::class, 'reference_id')
            ->where('reference_type', self::class);
    }

    /**
     * Recalcula subtotal, desconto e total a partir dos ITENS — nunca a partir do que já
     * estava gravado. Chamado por `SaleService` depois de toda mudança de item ou desconto.
     *
     * O desconto percentual é reaplicado sobre o subtotal novo: adicionar um item a uma venda
     * com 10% de desconto tem que dar 10% sobre o total maior, e é por isso que o TIPO fica
     * gravado ao lado do valor (ver `App\Enums\DiscountType`).
     */
    public function recalculateTotals(): void
    {
        $subtotal = round((float) $this->items()->sum('total'), 2);
        $discount = $this->discount_type->amountFor($subtotal, (float) $this->discount_value);

        $this->subtotal = $subtotal;
        $this->discount_amount = $discount;
        $this->total = round($subtotal - $discount, 2);
    }

    public function amountDue(): float
    {
        return round((float) $this->total - (float) $this->paid_amount, 2);
    }

    public function isFullyPaid(): bool
    {
        // Um centavo de tolerância: parcelamento em 3x de R$ 100,00 dá 33,33 × 3 = 99,99.
        return $this->amountDue() <= 0.01;
    }

    public function isQuote(): bool
    {
        return $this->kind === SaleKind::QUOTE;
    }

    /** @param  Builder<self>  $query */
    public function scopeSalesOnly(Builder $query): Builder
    {
        return $query->where('kind', SaleKind::SALE->value);
    }

    /** @param  Builder<self>  $query */
    public function scopeQuotesOnly(Builder $query): Builder
    {
        return $query->where('kind', SaleKind::QUOTE->value);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'total', 'discount_amount', 'client_id', 'cancelled_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
