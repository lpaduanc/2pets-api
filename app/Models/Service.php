<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Service extends Model
{
    protected $fillable = [
        'professional_id',
        'name',
        'description',
        'category',
        'duration',
        'price',
        'active',
    ];

    protected $casts = [
        'duration' => 'integer',
        'price' => 'decimal:2',
        'active' => 'boolean',
    ];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }
}
