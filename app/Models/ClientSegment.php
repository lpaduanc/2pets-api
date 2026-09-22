<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Segmento nomeado e salvo — contrato
 * `docs/gap-simplesvet/specs/18-segmentacao-clientes-spec.md`. `definition` é o JSON que
 * `ClientSegmentQueryBuilder::build()` traduz em query; consumido por `message-campaigns` (17)
 * e pelo BI (20).
 */
class ClientSegment extends Model
{
    protected $fillable = [
        'organization_id',
        'professional_id',
        'name',
        'definition',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'definition' => 'array',
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
