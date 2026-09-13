<?php

namespace App\Models;

use App\Enums\InventoryCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Inventory extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'professional_id',
        'organization_id',
        'item_name',
        'category',
        'quantity',
        'unit',
        'min_quantity',
        'cost_price',
        'selling_price',
        'supplier',
        'expiry_date',
    ];

    protected $casts = [
        'category' => InventoryCategory::class,
        'quantity' => 'integer',
        'min_quantity' => 'integer',
        'cost_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'expiry_date' => 'date',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    /** Estoque no ou abaixo do mínimo configurado — o alerta de reposição que faltava. */
    public function scopeLowStock(Builder $query): Builder
    {
        return $query->whereColumn('quantity', '<=', 'min_quantity');
    }

    public function scopeOfCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    public function scopeSearchByName(Builder $query, string $term): Builder
    {
        return $query->where('item_name', 'ilike', "%{$term}%");
    }
}
