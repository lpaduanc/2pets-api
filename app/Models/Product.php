<?php

namespace App\Models;

use App\Contracts\Sellable;
use App\Enums\ProductPurpose;
use App\Enums\StockMovementType;
use App\Services\Stock\StockService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Catálogo COMERCIAL da organização — o que se vende no balcão (doc 01), o que se compra do
 * fornecedor (doc 06) e, desde a consolidação de estoque
 * (docs/gap-simplesvet/specs/produtos-estoque-consolidado-spec.md), também o INSUMO CLÍNICO
 * (frasco de vacina, vermífugo, medicamento, insumo, equipamento) que antes vivia em
 * `Inventory`/`InventoryMovement`. `ClinicalStockDeductionService` decrementa esta tabela via
 * `StockService`, não mais `Inventory`.
 *
 * `Inventory`/`InventoryMovement` permanecem no schema só como legado histórico
 * (`@deprecated`, ver os próprios models) — a migration
 * `2026_11_03_100002_migrate_inventory_data_to_products` copiou cada linha para cá,
 * preservando a referência em `legacy_inventory_id`.
 *
 * `immunization_product_id` liga este produto (o item de estoque de verdade, com custo/lote)
 * ao catálogo clínico `ImmunizationProduct` (a identidade da vacina/vermífugo no calendário,
 * spec 13) — vários produtos comerciais (marcas/lotes diferentes) podem apontar para a mesma
 * identidade clínica, por isso o FK mora aqui e não o inverso.
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
        'last_supplier_id',
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
        'immunization_product_id',
        'legacy_inventory_id',
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

    public function lastSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'last_supplier_id');
    }

    /** Identidade clínica (vacina/vermífugo/antiparasitário) que este produto aplica — spec 13. */
    public function immunizationProduct(): BelongsTo
    {
        return $this->belongsTo(ImmunizationProduct::class);
    }

    /**
     * @deprecated Rastreabilidade da migração de `inventories` — não usar em regra de negócio
     *      nova. Ver docs/gap-simplesvet/specs/produtos-estoque-consolidado-spec.md.
     */
    public function legacyInventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class, 'legacy_inventory_id');
    }

    /** Livro de movimentos (doc 07). Só `StockService` escreve aqui. */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(ProductBatch::class);
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

    /**
     * Baixa do pedido de e-commerce. Passa pelo `StockService` (doc 07) para o saldo continuar
     * reconstituível pelo livro `stock_movements` — um `decrement()` direto aqui quebraria a
     * invariante `SUM(movimentos) = stock_quantity`.
     */
    public function decrementStock(int $quantity, ?Model $reference = null): void
    {
        if (! $this->track_inventory || $quantity < 1) {
            return;
        }

        app(StockService::class)->out($this, StockMovementType::SALE_OUT, $quantity, ['reference' => $reference]);
    }

    public function incrementStock(int $quantity, ?Model $reference = null): void
    {
        if (! $this->track_inventory || $quantity < 1) {
            return;
        }

        app(StockService::class)->in($this, StockMovementType::RETURN_IN, $quantity, ['reference' => $reference]);
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

    /**
     * Seletor clínico (spec produtos-estoque-consolidado, item 4): produtos que aplicam a
     * identidade clínica informada, para o vet escolher marca/lote na hora do ato.
     *
     * @param  Builder<self>  $query
     */
    public function scopeForImmunizationProduct(Builder $query, int $immunizationProductId): Builder
    {
        return $query->where('immunization_product_id', $immunizationProductId);
    }

    /**
     * Regra de negócio 3 da spec de consolidação: todo produto que representa uma identidade
     * clínica (vacina/vermífugo/antiparasitário) precisa rastrear lote, porque o lote aplicado
     * entra na carteira do pet (Res. CFMV 1.321/2020).
     */
    public function requiresBatchTracking(): bool
    {
        return $this->immunization_product_id !== null;
    }
}
