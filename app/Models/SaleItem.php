<?php

namespace App\Models;

use App\Contracts\Sellable;
use App\Services\Stock\StockValuation;
use App\Support\Money\Decimal;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Linha de venda — contrato docs/gap-simplesvet/01-caixa-pdv.md.
 *
 * `staff_id` (→ `organization_members`) é o "funcionário responsável por item" do SimplesVet,
 * e é O QUE ALIMENTA A COMISSÃO do doc 09. Dois itens da mesma venda podem ter responsáveis
 * diferentes — o banho é da groomer, a ração é do balconista —, e é essa granularidade que o
 * critério de aceite exige ("gera 2 registros de comissão").
 *
 * `description`, `unit_price`, `unit_cost` e `commission_percent` são CÓPIAS do estado do item
 * no momento da venda. Reimprimir uma venda de três meses atrás tem que mostrar o preço
 * daquele dia, não o de hoje.
 */
class SaleItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_id',
        'sellable_type',
        'sellable_id',
        'description',
        'staff_id',
        'quantity',
        'unit_price',
        'unit_cost',
        'discount',
        'total',
        'commission_percent',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
            'commission_percent' => 'decimal:4',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function sellable(): MorphTo
    {
        return $this->morphTo();
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(OrganizationMember::class, 'staff_id');
    }

    /**
     * Fechamento de comissão (doc 09) que já consumiu este item, se houver — `unique
     * (sale_item_id)` no banco garante no máximo um. `whereDoesntHave` sobre esta relação é
     * o que impede o mesmo item de entrar em dois fechamentos.
     */
    public function commissionSettlementItem(): HasOne
    {
        return $this->hasOne(CommissionSettlementItem::class);
    }

    /**
     * Total da linha: quantidade × preço − desconto. Único lugar que faz a conta; `SaleService`
     * chama daqui, nunca repete a fórmula.
     */
    public function calculateTotal(): float
    {
        return $this->totalMoney()->toFloat();
    }

    /** Base `margin` da comissão (doc 09): total da linha menos o custo das unidades vendidas. */
    public function marginAmount(): float
    {
        return $this->marginMoney()->toFloat();
    }

    public function marginMoney(): Money
    {
        $cost = Decimal::of($this->quantity)->multipliedBy(Decimal::of($this->unit_cost));

        return Money::of($this->totalMoney()->toBigDecimal()->minus($cost));
    }

    /**
     * Preço unitário efetivo da linha (já com o desconto), em centavos — só para exibir e gravar na
     * devolução. O valor devolvido NÃO é este × quantidade: é `refundFor()`.
     */
    public function netUnitPrice(): string
    {
        $quantity = Decimal::of($this->quantity);

        if (! $quantity->isPositive()) {
            return '0';
        }

        return (string) $this->totalMoney()->toBigDecimal()->dividedBy($quantity, StockValuation::COST_SCALE, RoundingMode::HalfUp);
    }

    /**
     * Quanto devolver por `$quantity` unidades, sabendo que `$alreadyReturned` já voltaram.
     * Rateio acumulado: o devolvido até aqui é sempre `total × devolvido ÷ vendido`, virando
     * centavo uma vez só; cada devolução leva a diferença. Devolver tudo, em quantas vezes for,
     * devolve EXATAMENTE o total da linha — nunca um centavo a mais nem a menos.
     */
    public function refundFor(Quantity $alreadyReturned, Quantity $quantity, ?Money $paid = null): Money
    {
        $paid ??= $this->totalMoney();

        return $this->cumulativeRefund($alreadyReturned->plus($quantity), $paid)->minus($this->cumulativeRefund($alreadyReturned, $paid));
    }

    /** `$paid`: o que o cliente pagou pela linha (com o desconto do cabeçalho, `Sale::netItemTotals`). */
    private function cumulativeRefund(Quantity $returned, Money $paid): Money
    {
        $sold = Quantity::of($this->quantity);

        if ($returned->isGreaterThanOrEqualTo($sold)) {
            return $paid;
        }

        return Money::of($paid->toBigDecimal()->multipliedBy($returned->toBigDecimal())
            ->dividedBy($sold->toBigDecimal(), StockValuation::VALUE_SCALE, RoundingMode::HalfUp));
    }

    /** Quantidade × preço − desconto, exato; arredonda uma vez e nunca fica negativo. */
    public function totalMoney(): Money
    {
        $gross = Decimal::of($this->quantity)->multipliedBy(Decimal::of($this->unit_price));

        return Money::of($gross->minus(Decimal::of($this->discount)))->max(0);
    }

    /**
     * O item baixa estoque? Delega ao `Sellable` — serviço nunca baixa, produto baixa se
     * controla estoque (doc 07). Item cujo produto foi apagado devolve `false` em vez de
     * explodir: a venda histórica continua legível mesmo sem o cadastro.
     */
    public function movesStock(): bool
    {
        $sellable = $this->sellable;

        return $sellable instanceof Sellable && $sellable->movesStock();
    }
}
