<?php

namespace App\Models;

use App\Contracts\HasDepositSettings;
use App\DataTransferObjects\Cnpj;
use App\Enums\ProfessionalType;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Professional extends Model implements HasDepositSettings
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'professional_type',
        'business_name',
        'cnpj',
        'specialties',
        // Fase 7 do fluxo de agendamento — especialidades da EQUIPE (membros bookáveis e
        // ativos da organização que este `Professional` possui), espelhadas por
        // `App\Services\Organization\TeamSpecialtyAggregator`. Só é preenchida para quem
        // é dono de organização; `null` para profissional avulso/membro comum.
        'team_specialties',
        'opening_hours',
        'closing_hours',
        'working_days',
        'description',
        'crmv',
        'crmv_state',
        // Perfil legal (spec 15) — título de como assina, registro no MAPA (só relevante
        // para quem emite documento de trânsito/sanidade animal, nunca obrigatório para
        // prescrição comum) e caminho da assinatura escaneada (storage privado).
        'title',
        'mapa_registration',
        'signature_image_path',
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
        'deposit_enabled',
        'deposit_percentage',
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
        'team_specialties' => 'array',
        'working_days' => 'array',
        'courses' => 'array',
        'services_offered' => 'array',
        'products_sold' => 'array',
        'equipment' => 'array',
        'certifications' => 'array',
        'graduation_year' => 'integer',
        'experience_years' => 'integer',
        'service_radius_km' => 'integer',
        'deposit_enabled' => 'boolean',
        'deposit_percentage' => 'decimal:2',
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

    /**
     * As especialidades do CATÁLOGO (`specialties`), pela pivô `professional_specialty`.
     *
     * ⚠️ Chama-se `catalogSpecialties` e não `specialties` de propósito: `specialties` é uma
     * COLUNA desta tabela (TEXT com JSON dentro, `casts` para array) e, no Eloquent, atributo
     * ganha da relação em `$model->specialties`. Duas coisas com o mesmo nome e precedência
     * silenciosa é a receita para alguém ler o array quando queria as linhas — ou o
     * contrário — sem erro nenhum aparecer.
     *
     * As duas convivem de propósito enquanto a coluna não for removida: a pivô é a fonte com
     * FK (ninguém grava rótulo inventado), a coluna é a fonte que a busca e três Resources
     * ainda leem. `App\Observers\ProfessionalSpecialtyObserver` mantém as duas iguais.
     */
    public function catalogSpecialties(): BelongsToMany
    {
        return $this->belongsToMany(Specialty::class, 'professional_specialty')->withTimestamps();
    }

    public function depositEnabled(): bool
    {
        return (bool) $this->deposit_enabled;
    }

    public function depositPercentage(): ?float
    {
        return $this->deposit_percentage === null ? null : (float) $this->deposit_percentage;
    }
}
