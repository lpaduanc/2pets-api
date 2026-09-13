<?php

namespace App\Models;

use App\DataTransferObjects\Cnpj;
use App\Enums\ProfessionalType;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Professional extends Model
{
    use HasFactory, SoftDeletes;

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
        // Espécies/portes atendidos (docs/segmentacao-cadastro-profissional.md §2) — gap que
        // o CLAUDE.md já prometia ("tipos de animais atendidos") e não existia em lugar nenhum.
        'species_served',
        'sizes_served',
        // Estrutura/comodidade (§4.2) — nunca para `vet`, ver `ProfessionalCapabilityRegistry`.
        'parking_available',
        'wheelchair_accessible',
        // Diferenciais por tipo (§4.3) — os 13 campos que `CompleteProfileProfessional.vue`
        // já capturava e o backend descartava por não estarem em nenhuma `rules()`.
        'accepts_credit_card',
        'accepts_pet_insurance',
        'home_visit_available',
        'online_consultation',
        'emergency_available',
        'emergency_24h',
        'delivery_available',
        'online_ordering',
        'cage_free_option',
        'webcam_access',
        'special_diet_accommodation',
        'mobile_service',
        'group_sessions_available',
        // Campos de enriquecimento com tipo próprio (`ProfessionalAdditionalField`).
        'exam_rooms_count',
        'training_methodology',
        'languages_spoken',
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
        'species_served' => 'array',
        'sizes_served' => 'array',
        'parking_available' => 'boolean',
        'wheelchair_accessible' => 'boolean',
        'accepts_credit_card' => 'boolean',
        'accepts_pet_insurance' => 'boolean',
        'home_visit_available' => 'boolean',
        'online_consultation' => 'boolean',
        'emergency_available' => 'boolean',
        'emergency_24h' => 'boolean',
        'delivery_available' => 'boolean',
        'online_ordering' => 'boolean',
        'cage_free_option' => 'boolean',
        'webcam_access' => 'boolean',
        'special_diet_accommodation' => 'boolean',
        'mobile_service' => 'boolean',
        'group_sessions_available' => 'boolean',
        'exam_rooms_count' => 'integer',
        'languages_spoken' => 'array',
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
