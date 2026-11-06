<?php

namespace App\Models;

use App\Enums\SalesGoalScope;
use Illuminate\Database\Eloquent\Model;

/** Meta de venda do mês (PLANO 2.5). */
class SalesGoal extends Model
{
    protected $fillable = ['organization_id', 'professional_id', 'month', 'scope', 'scope_id', 'amount', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['scope' => SalesGoalScope::class, 'scope_id' => 'integer', 'amount' => 'decimal:2'];
    }
}
