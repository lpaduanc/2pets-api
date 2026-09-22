<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PetDeworming extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'pet_id',
        'product_name',
        'applied_date',
        'next_date',
        'weight_at_application',
        'veterinarian_id',
        'inventory_id',
        'product_id',
        'product_batch_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'applied_date' => 'date',
            'next_date' => 'date',
            'weight_at_application' => 'decimal:2',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // ──────────────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────────────

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function veterinarian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'veterinarian_id');
    }

    /**
     * @deprecated Substituído por `product()`/`productBatch()` na consolidação de estoque
     *      (docs/gap-simplesvet/specs/produtos-estoque-consolidado-spec.md). Mantido só para
     *      histórico de linhas migradas antes da troca — não usar em regra de negócio nova.
     */
    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }

    /** Produto de estoque debitado por esta aplicação — nulo é o caso normal (ver item 2 do parecer). */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Lote aplicado — rastreabilidade exigida pela Res. CFMV 1.321/2020 (alterada pela 1.653/2025). */
    public function productBatch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class);
    }

    // ──────────────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────────────

    public function scopeUpcoming($query)
    {
        return $query->whereNotNull('next_date')
            ->where('next_date', '>=', now())
            ->orderBy('next_date');
    }

    public function scopeOverdue($query)
    {
        return $query->whereNotNull('next_date')
            ->where('next_date', '<', now())
            ->orderBy('next_date');
    }

    /**
     * Restringe à aplicação mais recente de CADA PET — mesmo raciocínio de
     * `Vaccination::scopeLatestPerType()`, mas aqui a chave é só `pet_id` (vermífugo não tem
     * "tipo" que precise de faixa própria como vacina). Sem isso, uma aplicação antiga
     * continuaria contando como "vencida" mesmo depois de uma aplicação mais nova.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLatestPerPet(Builder $query): Builder
    {
        return $query->whereIn('id', function (QueryBuilder $subQuery): void {
            $subQuery->selectRaw('DISTINCT ON (pet_id) id')
                ->from('pet_dewormings')
                ->whereNull('deleted_at')
                ->orderBy('pet_id')
                ->orderByDesc('applied_date')
                ->orderByDesc('id');
        });
    }
}
