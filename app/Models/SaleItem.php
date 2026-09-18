<?php

namespace App\Models;

use App\Contracts\Sellable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'unit_cost' => 'decimal:4',
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
     * Total da linha: quantidade × preço − desconto. Único lugar que faz a conta; `SaleService`
     * chama daqui, nunca repete a fórmula.
     */
    public function calculateTotal(): float
    {
        $gross = (float) $this->quantity * (float) $this->unit_price;

        return round(max(0, $gross - (float) $this->discount), 2);
    }

    /** Base `margin` da comissão (doc 09): total da linha menos o custo das unidades vendidas. */
    public function marginAmount(): float
    {
        return round($this->calculateTotal() - ((float) $this->quantity * (float) $this->unit_cost), 2);
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
