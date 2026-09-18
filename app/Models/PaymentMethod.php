<?php

namespace App\Models;

use App\Enums\PaymentDirection;
use App\Enums\PaymentMethodKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Forma de recebimento/pagamento CADASTRADA PELA CLÍNICA — "Maquininha Cielo", "Pix",
 * "Dinheiro". Contrato docs/gap-simplesvet/04.
 *
 * ⚠️ Homônima de `App\Enums\PaymentMethod`, que é outra coisa: aquele enum descreve o
 * pagamento do MARKETPLACE pelo gateway (Stripe/Mercado Pago), com 5 valores fixos para toda
 * a plataforma. Este model é o cadastro de cada clínica, com taxa e prazo próprios. Quem
 * precisa dos dois no mesmo arquivo importa um com alias.
 */
class PaymentMethod extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'professional_id',
        'name',
        'kind',
        'acquirer',
        'direction',
        'default_account_id',
        'fee_percent',
        'fee_fixed',
        'settlement_days',
        'max_installments',
        'display_order',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => PaymentMethodKind::class,
            'direction' => PaymentDirection::class,
            'fee_percent' => 'decimal:4',
            'fee_fixed' => 'decimal:2',
            'settlement_days' => 'integer',
            'max_installments' => 'integer',
            'display_order' => 'integer',
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

    public function defaultAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'default_account_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(SaleReceipt::class);
    }

    /**
     * Taxa da operadora sobre um valor recebido — percentual + fixo, exatamente como a
     * adquirente cobra. Arredonda a 2 casas no fim, não a cada parcela da conta, senão
     * 100 recebimentos de R$ 0,01 de taxa acumulam centavo de erro contra o extrato.
     */
    public function feeFor(float $amount): float
    {
        return round(($amount * ((float) $this->fee_percent / 100)) + (float) $this->fee_fixed, 2);
    }

    public function netFor(float $amount): float
    {
        return round($amount - $this->feeFor($amount), 2);
    }

    /**
     * Data prevista de depósito. `settlement_days = 0` devolve o próprio dia — dinheiro e Pix
     * já estão na conta.
     */
    public function expectedSettlementDate(\DateTimeInterface $receivedAt): \Carbon\CarbonImmutable
    {
        return \Carbon\CarbonImmutable::instance($receivedAt)->addDays($this->settlement_days);
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * `direction=in` traz também as `both` — uma forma que serve nos dois sentidos tem que
     * aparecer na lista de recebimento. Filtrar por igualdade estrita esconderia o Pix.
     *
     * @param  Builder<self>  $query
     */
    public function scopeForDirection(Builder $query, PaymentDirection $direction): Builder
    {
        if ($direction === PaymentDirection::BOTH) {
            return $query;
        }

        return $query->whereIn('direction', [$direction->value, PaymentDirection::BOTH->value]);
    }
}
