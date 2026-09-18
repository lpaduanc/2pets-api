<?php

namespace App\Models;

use App\Enums\FinancialAccountType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Conta onde o dinheiro da organização fica: banco, gaveta do caixa, carteira digital ou a
 * ADQUIRENTE (dinheiro em trânsito entre a venda e o depósito). Contrato docs/gap-simplesvet/04.
 *
 * Não tem coluna `balance` de propósito — ver `AccountBalanceService` e a migration.
 */
class FinancialAccount extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'professional_id',
        'name',
        'type',
        'bank_code',
        'branch',
        'branch_digit',
        'account_number',
        'account_digit',
        'allow_quick_entry',
        'opening_balance',
        'opening_balance_date',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => FinancialAccountType::class,
            'allow_quick_entry' => 'boolean',
            'opening_balance' => 'decimal:2',
            'opening_balance_date' => 'date',
            'active' => 'boolean',
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

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class, 'default_account_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashRegisterMovement::class, 'account_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(SaleReceipt::class, 'account_id');
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
