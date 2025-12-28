<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'professional_id',
        'category_id',
        'name',
        'description',
        'sku',
        'price',
        'compare_at_price',
        'stock_quantity',
        'track_inventory',
        'images',
        'is_active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'compare_at_price' => 'decimal:2',
        'stock_quantity' => 'integer',
        'track_inventory' => 'boolean',
        'images' => 'array',
        'is_active' => 'boolean',
    ];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class);
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function isInStock(): bool
    {
        if (!$this->track_inventory) {
            return true;
        }

        return $this->stock_quantity > 0;
    }

    public function hasStock(int $quantity): bool
    {
        if (!$this->track_inventory) {
            return true;
        }

        return $this->stock_quantity >= $quantity;
    }

    public function decrementStock(int $quantity): void
    {
        if (!$this->track_inventory) {
            return;
        }

        $this->decrement('stock_quantity', $quantity);
    }

    public function incrementStock(int $quantity): void
    {
        if (!$this->track_inventory) {
            return;
        }

        $this->increment('stock_quantity', $quantity);
    }
}

