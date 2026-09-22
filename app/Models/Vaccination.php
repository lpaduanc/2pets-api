<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Vaccination extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'pet_id',
        'professional_id',
        'appointment_id',
        'inventory_id',
        'product_id',
        'product_batch_id',
        'vaccine_name',
        'manufacturer',
        'batch_number',
        'expiry_date',
        'application_date',
        'next_dose_date',
        'dose_number',
        'notes',
        'adverse_reactions',
    ];

    protected $casts = [
        'expiry_date' => 'date',
        'application_date' => 'date',
        'next_dose_date' => 'date',
        'dose_number' => 'integer',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
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

    /** Produto de estoque debitado por esta dose — nulo é o caso normal (ver item 2 do parecer). */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Lote aplicado — rastreabilidade exigida pela Res. CFMV 1.321/2020 (alterada pela 1.653/2025). */
    public function productBatch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class);
    }

    public function scopeUpcoming($query)
    {
        return $query->where('next_dose_date', '>=', today())
            ->whereNotNull('next_dose_date');
    }

    /**
     * Restricts to the most recent dose of each vaccine type per pet.
     *
     * Vaccination history is append-only: a new dose never overwrites the old
     * row, so "overdue"/"up to date" must be judged by the newest dose per
     * (pet_id, vaccine_name) — otherwise every superseded dose keeps counting
     * as overdue forever. `vaccine_name` is a free string (no FK to
     * `vaccine_catalog` exists today), so it is the grouping key available.
     */
    public function scopeLatestPerType(Builder $query): Builder
    {
        return $query->whereIn('id', function (QueryBuilder $subQuery) {
            $subQuery->selectRaw('DISTINCT ON (pet_id, vaccine_name) id')
                ->from('vaccinations')
                ->whereNull('deleted_at')
                ->orderBy('pet_id')
                ->orderBy('vaccine_name')
                ->orderByDesc('application_date')
                ->orderByDesc('id');
        });
    }
}
