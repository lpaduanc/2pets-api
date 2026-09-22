<?php

namespace App\Models;

use App\Enums\PetImmunizationPlanStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PetImmunizationPlan extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'pet_id',
        'protocol_id',
        'started_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'date',
            'status' => PetImmunizationPlanStatus::class,
        ];
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function protocol(): BelongsTo
    {
        return $this->belongsTo(ImmunizationProtocol::class, 'protocol_id');
    }

    /** @return HasMany<PetImmunizationDose, $this> */
    public function doses(): HasMany
    {
        return $this->hasMany(PetImmunizationDose::class, 'plan_id')->orderBy('scheduled_for');
    }

    public function isActive(): bool
    {
        return $this->status === PetImmunizationPlanStatus::ACTIVE;
    }
}
