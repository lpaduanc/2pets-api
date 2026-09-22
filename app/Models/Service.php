<?php

namespace App\Models;

use App\Contracts\Sellable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Serviço vendável — consulta, banho, tosa, vacinação como ATO COBRÁVEL.
 *
 * Fica separado de `Product` porque a identificação fiscal é outra (código LC116/ISS
 * municipal, não NCM/ICMS); o que os dois compartilham é `Sellable`, e é por ele que
 * `sale_items` enxerga ambos (doc 08, "Decisão de modelagem").
 */
class Service extends Model implements Sellable
{
    use SoftDeletes;

    protected $fillable = [
        'professional_id',
        'organization_id',
        'name',
        'code',
        'description',
        'category',
        'product_group_id',
        'duration',
        'price',
        'commission_percent',
        'municipal_service_code',
        'lc116_code',
        'show_in_price_list',
        'allow_price_override',
        'active',
        // Fase 6 do fluxo de agendamento — override de sinal por serviço. `deposit_enabled`
        // é tri-state (`null` = sem override, herda do estabelecimento) — ver
        // `App\Services\Appointment\DepositConfigResolver`.
        'deposit_enabled',
        'deposit_percentage',
        // Item 21 do backlog gap-simplesvet — elegibilidade mínima por área de atendimento.
        // Nullable: serviço sem área não restringe elegibilidade (não regressivo).
        'service_area_id',
    ];

    protected $casts = [
        'duration' => 'integer',
        'price' => 'decimal:2',
        'commission_percent' => 'decimal:4',
        'show_in_price_list' => 'boolean',
        'allow_price_override' => 'boolean',
        'active' => 'boolean',
        'deposit_enabled' => 'boolean',
        'deposit_percentage' => 'decimal:2',
    ];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'product_group_id');
    }

    public function serviceArea(): BelongsTo
    {
        return $this->belongsTo(ServiceArea::class);
    }

    public function saleItems(): MorphMany
    {
        return $this->morphMany(SaleItem::class, 'sellable');
    }

    // ----------------------------------------------------------------------
    // Sellable
    // ----------------------------------------------------------------------

    public function sellableName(): string
    {
        return $this->name;
    }

    public function sellableUnitPrice(): float
    {
        return (float) $this->price;
    }

    public function allowsPriceOverride(): bool
    {
        return (bool) $this->allow_price_override;
    }

    public function commissionPercent(): ?float
    {
        return $this->commission_percent === null ? null : (float) $this->commission_percent;
    }

    /** Serviço nunca baixa estoque: o insumo consumido nele é outro item (doc 07). */
    public function movesStock(): bool
    {
        return false;
    }

    /**
     * Serviço não tem custo de aquisição no cadastro. A base de comissão `margin` (doc 09)
     * aplicada a serviço equivale a `gross` — e é essa a leitura correta: a margem de um
     * serviço é o próprio preço, já que o custo dele é mão de obra, não mercadoria.
     */
    public function unitCost(): float
    {
        return 0.0;
    }

    public function appearsInPriceList(): bool
    {
        return (bool) $this->show_in_price_list;
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
