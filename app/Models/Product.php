<?php

namespace App\Models;

use App\Contracts\Sellable;
use App\Enums\ProductPurpose;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Catálogo COMERCIAL da organização — o que se vende no balcão (doc 01) e o que se compra do
 * fornecedor (doc 06). Nasceu como tabela de e-commerce e foi promovida a catálogo em
 * `2026_10_03_100001_add_commercial_fields_to_products_table`.
 *
 * Não confundir com `Inventory`, que é o INSUMO CLÍNICO (frasco de vacina, vermífugo) que
 * `ClinicalStockDeductionService` decrementa quando o vet aplica. São dois estoques com donos
 * e regras diferentes, e a migration citada explica por que não foram fundidos.
 */
class Product extends Model implements Sellable
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'professional_id',
        'organization_id',
        'category_id',
        'product_group_id',
        'brand_id',
        'name',
        'description',
        'sku',
        'code',
        'gtin',
        'ncm',
        'cest',
        'unit_of_sale',
        'purpose',
        'price',
        'average_cost',
        'last_cost',
        'markup_percent',
        'commission_percent',
        'compare_at_price',
        'stock_quantity',
        'track_inventory',
        'show_in_price_list',
        'allow_price_override',
        'controls_stock',
        'track_batches',
        'min_stock',
        'max_stock',
        'expiry_date',
        'images',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => ProductPurpose::class,
            'price' => 'decimal:2',
            'average_cost' => 'decimal:4',
            'last_cost' => 'decimal:4',
            'markup_percent' => 'decimal:4',
            'commission_percent' => 'decimal:4',
            'compare_at_price' => 'decimal:2',
            'stock_quantity' => 'integer',
            'min_stock' => 'integer',
            'max_stock' => 'integer',
            'expiry_date' => 'date',
            'track_inventory' => 'boolean',
            'show_in_price_list' => 'boolean',
            'allow_price_override' => 'boolean',
            'controls_stock' => 'boolean',
            'track_batches' => 'boolean',
            'is_active' => 'boolean',
            'images' => 'array',
        ];
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'product_group_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
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

    public function movesStock(): bool
    {
        return (bool) $this->controls_stock;
    }

    public function unitCost(): float
    {
        return (float) $this->average_cost;
    }

    public function appearsInPriceList(): bool
    {
        return (bool) $this->show_in_price_list;
    }

    // ----------------------------------------------------------------------

    public function isInStock(): bool
    {
        if (! $this->track_inventory) {
            return true;
        }

        return $this->stock_quantity > 0;
    }

    public function hasStock(int $quantity): bool
    {
        if (! $this->track_inventory) {
            return true;
        }

        return $this->stock_quantity >= $quantity;
    }

    public function decrementStock(int $quantity): void
    {
        if (! $this->track_inventory) {
            return;
        }

        $this->decrement('stock_quantity', $quantity);
    }

    public function incrementStock(int $quantity): void
    {
        if (! $this->track_inventory) {
            return;
        }

        $this->increment('stock_quantity', $quantity);
    }

    /**
     * Situação do estoque exibida na listagem do doc 08. `controls_stock = false` devolve
     * `untracked` em vez de "sem estoque": item que não controla estoque nunca deve aparecer
     * como faltando (critério de aceite do doc 08).
     */
    public function stockSituation(): string
    {
        if (! $this->controls_stock) {
            return 'untracked';
        }

        return match (true) {
            $this->stock_quantity <= 0 => 'out_of_stock',
            $this->stock_quantity <= $this->min_stock => 'low',
            $this->max_stock !== null && $this->stock_quantity > $this->max_stock => 'over',
            default => 'ok',
        };
    }

    public function isExpired(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->isPast();
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** @param  Builder<self>  $query */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNotNull('expiry_date')->whereDate('expiry_date', '<', now());
    }

    /** @param  Builder<self>  $query */
    public function scopeLowStock(Builder $query): Builder
    {
        return $query->where('controls_stock', true)->whereColumn('stock_quantity', '<=', 'min_stock');
    }
}
