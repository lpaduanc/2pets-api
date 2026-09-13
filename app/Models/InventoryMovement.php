<?php

namespace App\Models;

use App\Enums\InventoryMovementType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Livro-razão append-only de toda mudança em `inventories.quantity` — docs/vinculo-estoque-
 * aplicacao-clinica.md item 6. Nunca `update()`, nunca `delete()`: correção de um lançamento
 * errado é um novo movimento de ajuste, nunca a edição do antigo.
 *
 * `SUM(quantity_delta)` por `inventory_id` deve sempre bater com `inventories.quantity`.
 */
class InventoryMovement extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'inventory_id',
        'organization_id',
        'professional_id',
        'type',
        'quantity_delta',
        'reference_type',
        'reference_id',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => InventoryMovementType::class,
            'quantity_delta' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }
}
