<?php

namespace App\Models;

use App\Enums\PromotionType;
use App\Support\BusinessClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Promoção do PDV (PLANO 3.2). Vigência por data de negócio, inclusiva nas duas pontas. */
class Promotion extends Model
{
    use SoftDeletes;

    protected $fillable = ['organization_id', 'professional_id', 'name', 'type', 'starts_at', 'ends_at', 'priority', 'stackable', 'active', 'config', 'created_by'];

    protected $attributes = ['priority' => 0, 'stackable' => false, 'active' => true];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => PromotionType::class,
            'starts_at' => 'date',
            'ends_at' => 'date',
            'priority' => 'integer',
            'stackable' => 'boolean',
            'active' => 'boolean',
            'config' => 'array',
        ];
    }

    /** @param  Builder<self>  $query */
    public function scopeInEffect(Builder $query): Builder
    {
        $today = BusinessClock::todayString();

        return $query->where('active', true)
            ->whereDate('starts_at', '<=', $today)
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhereDate('ends_at', '>=', $today));
    }

    /** Formato do `PromotionEngine`. @return array<string, mixed> */
    public function toEngine(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'type' => $this->type->value, 'priority' => $this->priority, 'stackable' => $this->stackable, 'config' => $this->config];
    }
}
