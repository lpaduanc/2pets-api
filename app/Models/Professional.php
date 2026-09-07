<?php

namespace App\Models;

use App\DataTransferObjects\Cnpj;
use App\Enums\ProfessionalType;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
        // Badge "verificado" (CLAUDE.md §2) — só é setado de verdade via
        // `Professional::where(...)->update()` no AdminController::verifyDocument, que é uma
        // escrita em massa via query builder e por isso ignora $fillable. Ainda assim precisa
        // estar aqui: qualquer escrita via instância (Model::create()/Model::update(), inclusive
        // em teste) passa pela guarda de mass assignment.
        'is_crmv_verified',
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
        'average_rating',
        'total_reviews',
        'is_featured',
    ];

    protected $casts = [
        'professional_type' => ProfessionalType::class,
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
        'average_rating' => 'decimal:2',
        'total_reviews' => 'integer',
        'is_featured' => 'boolean',
    ];

    /**
     * Regra de projeto: documento sempre gravado limpo, só dígitos. Ver `DocumentNumber`.
     * Sem isto, um cadastro que envia `12.345.678/0001-90` grava a máscara e a coluna
     * `professionals_cnpj_unique` deixa de detectar o mesmo CNPJ escrito de outra forma.
     */
    protected function cnpj(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => Cnpj::normalizeForStorage($value),
        );
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function technicalResponsible()
    {
        return $this->belongsTo(User::class, 'technical_responsible_id');
    }

    public function services()
    {
        return $this->hasMany(\App\Models\Service::class, 'professional_id', 'user_id')
            ->where('active', true);
    }

    public function allServices()
    {
        return $this->hasMany(\App\Models\Service::class, 'professional_id', 'user_id');
    }
}
