<?php

namespace App\Models;

use App\Enums\FinancialEntryStatus;
use App\Enums\FinancialNature;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Lançamento contábil (receita/despesa) — o livro-razão do DRE. Contrato
 * docs/gap-simplesvet/02-financeiro-plano-de-contas-dre.md.
 *
 * Não confundir com `CashRegisterMovement` (razão OPERACIONAL da gaveta) nem com a leitura de
 * `invoices`/`sales` do `FinancialOverviewService` — os três convivem, ver cabeçalho da
 * migration `create_financial_entries_table`.
 *
 * `accrual_date` (competência) e `paid_at` (caixa) na MESMA linha: o relatório escolhe qual
 * data agrupa por mês, nunca duplica o lançamento.
 */
class FinancialEntry extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'professional_id',
        'financial_category_id',
        'account_id',
        'supplier_id',
        'payment_method_id',
        'description',
        'nature',
        'due_date',
        'accrual_date',
        'amount',
        'discount',
        'fine',
        'interest',
        'net_amount',
        'paid_at',
        'paid_amount',
        'status',
        'series_id',
        'installment_number',
        'installment_total',
        'reference_type',
        'reference_id',
        'notes',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nature' => FinancialNature::class,
            'status' => FinancialEntryStatus::class,
            'due_date' => 'date',
            'accrual_date' => 'date',
            'amount' => 'decimal:2',
            'discount' => 'decimal:2',
            'fine' => 'decimal:2',
            'interest' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'paid_amount' => 'decimal:2',
            'installment_number' => 'integer',
            'installment_total' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinancialCategory::class, 'financial_category_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'account_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOverdue(): bool
    {
        return $this->status !== FinancialEntryStatus::PAID
            && $this->status !== FinancialEntryStatus::CANCELLED
            && $this->due_date->isBefore(now()->startOfDay());
    }

    /** @param  Builder<self>  $query */
    public function scopeNature(Builder $query, FinancialNature $nature): Builder
    {
        return $query->where('nature', $nature->value);
    }

    /** @param  Builder<self>  $query */
    public function scopeOpenOrPartial(Builder $query): Builder
    {
        return $query->whereIn('status', [FinancialEntryStatus::OPEN->value, FinancialEntryStatus::PARTIALLY_PAID->value]);
    }
}
