<?php

namespace App\Models;

use App\Enums\CostCenterType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Centro de custo da organização (PLANO 2.4). */
class CostCenter extends Model
{
    use SoftDeletes;

    protected $fillable = ['organization_id', 'professional_id', 'name', 'type', 'active'];

    protected $attributes = ['active' => true];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['type' => CostCenterType::class, 'active' => 'boolean'];
    }
}
