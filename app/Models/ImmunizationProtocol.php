<?php

namespace App\Models;

use App\Enums\ImmunizationApplicationMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ImmunizationProtocol extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'immunization_product_id',
        'organization_id',
        'name',
        'application_mode',
        'total_doses',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'application_mode' => ImmunizationApplicationMode::class,
            'total_doses' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ImmunizationProduct::class, 'immunization_product_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<ImmunizationProtocolDose, $this> */
    public function doses(): HasMany
    {
        return $this->hasMany(ImmunizationProtocolDose::class, 'protocol_id')->orderBy('dose_number');
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
