<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InsuranceProvider extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'logo_url',
        'description',
        'api_endpoint',
        'api_key',
        'coverage_types',
        'is_active',
    ];

    protected $casts = [
        'coverage_types' => 'array',
        'is_active' => 'boolean',
    ];

    protected $hidden = [
        'api_key',
    ];

    public function petInsurances(): HasMany
    {
        return $this->hasMany(PetInsurance::class);
    }
}

