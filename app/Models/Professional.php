<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Professional extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'professional_type',
        'business_name',
        'cnpj',
        'specialties',
        'opening_hours',
        'closing_hours',
        'working_days',
        'description',
        'crmv',
        'crmv_state',
        'university',
        'graduation_year',
        'courses',
        'experience_years',
        'technical_responsible_id',
        'technical_responsible_name',
        'technical_responsible_crmv',
        'technical_responsible_crmv_state',
        'service_radius_km',
        'services_offered',
        'products_sold',
        'equipment',
        'certifications',
    ];

    protected $casts = [
        'specialties' => 'array',
        'working_days' => 'array',
        'courses' => 'array',
        'services_offered' => 'array',
        'products_sold' => 'array',
        'equipment' => 'array',
        'certifications' => 'array',
        'graduation_year' => 'integer',
        'experience_years' => 'integer',
        'service_radius_km' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function technicalResponsible()
    {
        return $this->belongsTo(User::class, 'technical_responsible_id');
    }
}
