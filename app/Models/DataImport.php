<?php

namespace App\Models;

use App\Enums\Import\ImportEntity;
use App\Enums\Import\ImportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma importação de planilha (item 26 do backlog gap-simplesvet) — dono via
 * `organization_id`/`professional_id` (mesmo padrão do grupo comercial), `user_id` é quem
 * de fato clicou em importar.
 */
class DataImport extends Model
{
    protected $fillable = [
        'organization_id',
        'professional_id',
        'user_id',
        'source',
        'entity',
        'file_path',
        'status',
        'total_rows',
        'valid_rows',
        'error_rows',
        'imported_rows',
        'column_mapping',
        'raw_headers',
        'options',
        'started_at',
        'finished_at',
        'batch_uuid',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entity' => ImportEntity::class,
            'status' => ImportStatus::class,
            'column_mapping' => 'array',
            'raw_headers' => 'array',
            'options' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(DataImportRow::class);
    }
}
