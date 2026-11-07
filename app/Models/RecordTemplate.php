<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Modelo de prontuário (doc 14; PLANO 3.8). Global quando `organization_id` é nulo. */
class RecordTemplate extends Model
{
    use SoftDeletes;

    protected $fillable = ['organization_id', 'name', 'description', 'body_html', 'active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, ?int $organizationId): Builder
    {
        return $query->where(function (Builder $scoped) use ($organizationId): void {
            $scoped->whereNull('organization_id');

            if ($organizationId !== null) {
                $scoped->orWhere('organization_id', $organizationId);
            }
        });
    }
}
