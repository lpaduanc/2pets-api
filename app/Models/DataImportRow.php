<?php

namespace App\Models;

use App\Enums\Import\ImportRowStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uma linha da planilha (item 26 do backlog gap-simplesvet). */
class DataImportRow extends Model
{
    protected $fillable = [
        'data_import_id',
        'row_number',
        'raw',
        'normalized',
        'status',
        'errors',
        'created_record_type',
        'created_record_id',
        'matched_existing_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'raw' => 'array',
            'normalized' => 'array',
            'errors' => 'array',
            'status' => ImportRowStatus::class,
        ];
    }

    public function dataImport(): BelongsTo
    {
        return $this->belongsTo(DataImport::class);
    }

    /** @param  Builder<self>  $query */
    public function scopeImported(Builder $query): Builder
    {
        return $query->where('status', ImportRowStatus::IMPORTED->value);
    }

    /** @param  Builder<self>  $query */
    public function scopeInvalid(Builder $query): Builder
    {
        return $query->where('status', ImportRowStatus::INVALID->value);
    }
}
