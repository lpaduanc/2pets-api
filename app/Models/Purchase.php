<?php

namespace App\Models;

use App\Enums\PurchaseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Compra (entrada de nota) — contrato docs/gap-simplesvet/06. Só `PurchaseService` muda o
 * status; o efeito em estoque/custo/preço/parcelas acontece em `receive()`.
 */
class Purchase extends Model
{
    use LogsActivity, SoftDeletes;

    public const RESOURCE_RELATIONS = [
        'supplier',
        'paymentMethod',
        'financialAccount',
        'items.product',
        'installments.paymentMethod',
        'installments.financialAccount',
    ];

    /** Totais de cabeçalho derivados dos itens (docs/gap-petflow/PLANO.md, 1.2). */
    public const COST_TOTALS = [
        'total_products', 'total_discount', 'total_freight', 'total_insurance', 'total_other',
        'total_ipi', 'total_icms_st', 'total',
    ];

    protected $fillable = [
        'organization_id',
        'professional_id',
        'code',
        'supplier_id',
        'purchase_order_id',
        'invoice_number',
        'invoice_series',
        'invoice_key',
        'invoice_issued_at',
        'entered_at',
        'total_products',
        'total_freight',
        'total_insurance',
        'total_other',
        'total_ipi',
        'total_icms_st',
        'total_discount',
        'total',
        'payment_method_id',
        'financial_account_id',
        'installments_count',
        'first_due_date',
        'installment_interval_days',
        'status',
        'xml_path',
        'notes',
        'received_at',
        'cancelled_at',
        'cancellation_reason',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PurchaseStatus::class,
            'invoice_issued_at' => 'date',
            'first_due_date' => 'date',
            'installments_count' => 'integer',
            'installment_interval_days' => 'integer',
            'entered_at' => 'datetime',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'total_products' => 'decimal:2',
            'total_freight' => 'decimal:2',
            'total_insurance' => 'decimal:2',
            'total_other' => 'decimal:2',
            'total_ipi' => 'decimal:2',
            'total_icms_st' => 'decimal:2',
            'total_discount' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'total', 'invoice_number', 'invoice_key', 'cancellation_reason'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function installments(): HasMany
    {
        return $this->hasMany(PurchaseInstallment::class)->orderBy('number');
    }

    public function isDraft(): bool
    {
        return $this->status === PurchaseStatus::DRAFT;
    }

    /**
     * Grava os totais calculados pelo `PurchaseCostAllocator` — a mesma conta dos itens, para o
     * cabeçalho nunca divergir deles: total = produtos − desconto + frete + seguro + outras +
     * IPI + ICMS-ST (o vNF da NF-e).
     *
     * @param  array<string, string>  $totals
     */
    public function applyCostTotals(array $totals): void
    {
        $this->forceFill(array_intersect_key($totals, array_flip(self::COST_TOTALS)));
    }
}
